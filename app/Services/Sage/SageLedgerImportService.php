<?php

declare(strict_types=1);

namespace App\Services\Sage;

use App\Models\Area;
use App\Models\AreaType;
use App\Models\Municipality;
use App\Models\ServiceType;
use App\Models\User;
use App\Support\Tenancy\CurrentMunicipality;
use Illuminate\Support\Facades\DB;

/**
 * Imports Gokwe South's ratepayers from the Sage debtor ledger.
 *
 * Councils that do not run the CCG Municipal Billing (`_mtbl`) property module
 * keep their properties as Sage debtor accounts instead. Each account is coded
 * `{STAND}-{TYPE}-{portion}` (e.g. `PLT006-ASSR-P1SP4`), so one physical
 * property/stand owns several service accounts (assessment rates, licences,
 * development levies, land lease, …). This importer therefore:
 *
 *   • groups the `Client` accounts by their stand prefix → one Customer per stand
 *   • turns each distinct type token into a Service the stand is subscribed to
 *   • resolves the stand's ward from `Areas` (via Client.iAreasID)
 *
 * Re-running is idempotent: areas key on a stable `sage_id`, customers on their
 * account number, service types on a code; subscriptions are rebuilt.
 */
final class SageLedgerImportService
{
    private const SAGE = 'sage';

    private int $municipalityId;

    /** Sage Areas.idAreas => O-Billing area id. */
    private array $areaMap = [];

    private ?int $fallbackAreaId = null;

    /** account-type token => O-Billing service (variant) id. */
    private array $serviceByToken = [];

    private array $counts = [];

    private array $warnings = [];

    /** Friendly names for the known Gokwe South account-type tokens. */
    private const TOKEN_SERVICES = [
        'ASSR' => 'Assessment Rates',
        'ASS' => 'Assessment Rates',
        'LIC' => 'Licence',
        'LICR' => 'Licence (Rural)',
        'DEVC' => 'Development Levy',
        'DEVR' => 'Development Levy (Rural)',
        'DEVM' => 'Development Levy (Mining)',
        'LEAR' => 'Land Lease',
        'STD' => 'Stand Charge',
    ];

    /** Tokens that indicate a commercial ratepayer. */
    private const BUSINESS_TOKENS = ['LIC', 'LICR'];

    /**
     * @return array{counts: array<string,int>, warnings: list<string>, municipality: string}
     */
    public function run(): array
    {
        $muni = $this->importMunicipality();

        return app(CurrentMunicipality::class)->runFor($this->municipalityId, function () use ($muni): array {
            DB::transaction(function (): void {
                $this->importAreas();
                $this->importClients();
            });

            return [
                'counts' => $this->counts,
                'warnings' => $this->warnings,
                'municipality' => $muni->name,
            ];
        });
    }

    private function importMunicipality(): Municipality
    {
        $muni = Municipality::firstOrNew(['code' => config('sage.municipality.code')]);
        $muni->fill([
            'name' => $muni->name ?: config('sage.municipality.name'),
            'base_currency' => 'USD',
            'supported_currencies' => ['USD'],
            'tax_label' => 'VAT',
            'active' => true,
        ]);
        if (! $muni->exists) {
            $muni->tax_rate = 0.155; // matches the Sage company's Output Tax rate
        }
        $muni->setup_completed_at ??= now();
        $muni->save();

        $muni->users()->syncWithoutDetaching(User::pluck('id')->all());

        $this->municipalityId = $muni->id;

        return $muni;
    }

    /** The ward each ratepayer sits in, from the Sage `Areas` table. */
    private function importAreas(): void
    {
        $wardType = $this->areaType(1, 'Ward', true);

        foreach (DB::connection(self::SAGE)->table('Areas')->get() as $a) {
            $this->areaMap[$a->idAreas] = $this->upsertArea(
                "ward:{$a->idAreas}", $wardType->id, $a->Description ?: ($a->Code ?: 'Ward'), $a->Code
            )->id;
        }

        // Catch-all for accounts with no (or an unknown) ward.
        $this->fallbackAreaId = $this->upsertArea('ward:unknown', $wardType->id, '(Unknown Ward)', null)->id;

        $this->counts['areas'] = count($this->areaMap);
    }

    private function areaType(int $level, string $name, bool $billing): AreaType
    {
        $type = AreaType::firstOrNew(['municipality_id' => $this->municipalityId, 'level' => $level]);
        $type->fill(['name' => $name, 'is_billing_level' => $billing])->save();

        return $type;
    }

    private function upsertArea(string $sageKey, int $typeId, string $name, ?string $code): Area
    {
        $area = Area::firstOrNew(['municipality_id' => $this->municipalityId, 'sage_id' => $sageKey]);
        $area->fill([
            'area_type_id' => $typeId,
            'name' => $name,
            'code' => $code,
        ])->save();

        return $area;
    }

    /**
     * Group the debtor ledger into stands (customers) and their services.
     */
    private function importClients(): void
    {
        $rows = DB::connection(self::SAGE)->table('Client')
            ->select('Account', 'Name', 'iAreasID', 'Telephone', 'DCBalance')
            ->get();

        // Fold the per-service accounts up into one record per physical stand.
        $stands = [];
        foreach ($rows as $r) {
            [$prefix, $token] = $this->splitAccount((string) $r->Account);
            if ($prefix === '') {
                continue;
            }

            if (! isset($stands[$prefix])) {
                $stands[$prefix] = [
                    'name' => trim((string) $r->Name),
                    'area' => $r->iAreasID,
                    'phone' => trim((string) $r->Telephone) ?: null,
                    'tokens' => [],
                    'balance' => 0.0,
                ];
            }
            $s = &$stands[$prefix];
            $s['name'] = $s['name'] ?: trim((string) $r->Name);
            $s['area'] = $s['area'] ?: $r->iAreasID;
            $s['phone'] = $s['phone'] ?: (trim((string) $r->Telephone) ?: null);
            $s['tokens'][$token] = true;
            $s['balance'] += (float) $r->DCBalance;
            unset($s);
        }

        // Make sure every token seen has a Service to subscribe to.
        $allTokens = [];
        foreach ($stands as $s) {
            foreach ($s['tokens'] as $token => $_) {
                $allTokens[$token] = true;
            }
        }
        foreach (array_keys($allTokens) as $token) {
            $this->ensureService($token);
        }

        $now = now();
        $customerRows = [];
        foreach ($stands as $prefix => $s) {
            $areaId = ($s['area'] !== null ? ($this->areaMap[$s['area']] ?? null) : null) ?? $this->fallbackAreaId;
            $customerRows[] = [
                'municipality_id' => $this->municipalityId,
                'area_id' => $areaId,
                'account_number' => $prefix,
                'name' => $s['name'] ?: '(No name)',
                'type' => $this->mapType(array_keys($s['tokens'])),
                'phone' => $s['phone'],
                'currency' => 'USD',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($customerRows, 500) as $chunk) {
            DB::table('customers')->upsert(
                $chunk,
                ['municipality_id', 'account_number'],
                ['area_id', 'name', 'type', 'phone', 'currency', 'active', 'updated_at'],
            );
        }

        $accountId = DB::table('customers')->where('municipality_id', $this->municipalityId)
            ->pluck('id', 'account_number');

        // Rebuild the customer↔service links from each stand's tokens.
        $customerIds = [];
        $pivot = [];
        foreach ($stands as $prefix => $s) {
            $customerId = $accountId[$prefix] ?? null;
            if ($customerId === null) {
                continue;
            }
            $customerIds[] = $customerId;
            foreach (array_keys($s['tokens']) as $token) {
                $serviceId = $this->serviceByToken[$token] ?? null;
                if ($serviceId !== null) {
                    $pivot[] = ['customer_id' => $customerId, 'service_id' => $serviceId, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }

        foreach (array_chunk($customerIds, 500) as $chunk) {
            DB::table('customer_service')->whereIn('customer_id', $chunk)->delete();
        }
        foreach (array_chunk($pivot, 1000) as $chunk) {
            DB::table('customer_service')->insert($chunk);
        }

        $this->counts['customers'] = count($stands);
        $this->counts['services'] = count($this->serviceByToken);
        $this->counts['subscriptions'] = count($pivot);

        $this->warnings[] = 'Ratepayers were grouped from Sage debtor accounts by stand code; each account-type token became a Service. No rates/tariffs exist in the ledger, so services were imported without a price — set tariffs before billing.';
        $nonZero = count(array_filter($stands, fn ($s) => abs($s['balance']) > 0.005));
        if ($nonZero > 0) {
            $this->warnings[] = "{$nonZero} stand(s) carry an outstanding Sage balance; balances were not imported (O-Billing has no opening-balance concept yet).";
        }
    }

    /** Create (once) the ServiceType + default Service for an account-type token. */
    private function ensureService(string $token): void
    {
        if (isset($this->serviceByToken[$token])) {
            return;
        }

        $name = self::TOKEN_SERVICES[$token] ?? ucfirst(strtolower($token));
        $type = ServiceType::firstOrNew(['municipality_id' => $this->municipalityId, 'code' => "LEDGER-{$token}"]);
        $type->fill([
            'name' => $name,
            'billing_basis' => ServiceType::BASIS_FLAT,
            'active' => true,
        ])->save();

        $this->serviceByToken[$token] = $type->ensureDefaultService()->id;
    }

    /**
     * The stand prefix and account-type token from a `{STAND}-{TYPE}-{portion}`
     * account code. Accounts without a token fall back to "(other)".
     *
     * @return array{0:string, 1:string}
     */
    private function splitAccount(string $account): array
    {
        $parts = explode('-', $account);
        $prefix = trim($parts[0]);
        $token = isset($parts[1]) ? strtoupper(trim($parts[1])) : '(other)';

        return [$prefix, $token ?: '(other)'];
    }

    /** @param list<string> $tokens */
    private function mapType(array $tokens): string
    {
        return array_intersect($tokens, self::BUSINESS_TOKENS) !== [] ? 'business' : 'residential';
    }
}
