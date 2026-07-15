<?php

declare(strict_types=1);

namespace App\Services\Sage;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new property in Sage so a property added in O-Billing shows up
 * there. Sage companies keep properties in one of two shapes, detected from
 * the write target's schema:
 *
 *  • Property module (`_ccg_EB_Properties`): a property row (keyed on its erf
 *    number) plus, if needed, its owner debtor (`Client`) and a
 *    `_ccg_EB_PropertyServices` link per subscribed service.
 *
 *  • Debtors ledger (no property tables — e.g. Gokwe South): a property IS its
 *    debtor accounts, one per service, coded `{STAND}-{TOKEN}-{portion}`
 *    (see SageLedgerImportService). One `Client` row is created per subscribed
 *    ledger service, copying the class / terms / portion convention from that
 *    token's existing accounts so statements and billing posting resolve the
 *    new account exactly like the rest.
 *
 * Debtor control in Sage Evolution is a company-level GL account, not
 * per-client, and `Client` has no triggers or required columns, so a new
 * debtor is inert (no ledger impact) until it is actually billed.
 *
 * All writes go to the `sage_write` connection (the NON-PRODUCTION test company
 * by default), and all lookups resolve against that same target.
 */
final class SagePropertyWriter
{
    private const CONN = 'sage_write';

    /** Whether the write target has the CCG property module tables. */
    public function targetsPropertyModule(): bool
    {
        static $has = null;

        return $has ??= DB::connection(self::CONN)->getSchemaBuilder()->hasTable('_ccg_EB_Properties');
    }

    /**
     * @return array<string, mixed> Always contains ok/database/mode; on success,
     *                              property-module pushes add property_id / owner_dclink / owner_created /
     *                              area_linked / services / erf, ledger pushes add stand / created / existing / unmapped.
     */
    public function pushProperty(Customer $customer): array
    {
        $database = (string) config('database.connections.'.self::CONN.'.database');
        $account = trim((string) $customer->account_number);

        if ($account === '') {
            return ['ok' => false, 'database' => $database,
                'error' => 'The property needs an account/stand number before it can be sent to Sage.'];
        }

        return $this->targetsPropertyModule()
            ? $this->pushPropertyModule($customer, $database, $account)
            : $this->pushLedgerAccounts($customer, $database, $account);
    }

    // ------------------------------------------------------------------
    // Property module (_ccg_EB_*) target
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function pushPropertyModule(Customer $customer, string $database, string $erf): array
    {
        // Don't create a duplicate erf.
        if (DB::connection(self::CONN)->table('_ccg_EB_Properties')->where('ErfNumber', $erf)->exists()) {
            return ['ok' => false, 'database' => $database,
                'error' => "A property with erf {$erf} already exists in {$database}."];
        }

        $sageAreaId = $this->resolveAreaId($customer);
        $municipalityId = DB::connection(self::CONN)->table('_ccg_EB_Municipalities')->min('ID');

        $result = DB::connection(self::CONN)->transaction(function () use ($customer, $erf, $sageAreaId, $municipalityId): array {
            [$ownerDclink, $ownerCreated] = $this->resolveOwner($customer, $erf);

            $propertyId = (int) DB::connection(self::CONN)->table('_ccg_EB_Properties')->insertGetId([
                'ErfNumber' => $erf,
                'OwnerID' => $ownerDclink,
                'AreaID' => $sageAreaId,
                'MunicipalityID' => $municipalityId,
                'MarketValue' => (float) ($customer->property_value ?? 0),
                'LandValue' => (float) ($customer->land_value ?? 0),
                'ImprovementValue' => (float) ($customer->improvement_value ?? 0),
                'LandSize' => (float) ($customer->land_size ?? 0),
                'AddressLine1' => $customer->address,
                'RegisteredOwnerName' => $customer->name,
                'SubDivided' => false,
                'Households' => 1,
                'UserCreated' => 'O-Billing',
                'DateCreated' => now(),
            ], 'ID');

            $services = $this->createPropertyServices($customer, $propertyId, $ownerDclink);

            return [$propertyId, $ownerDclink, $ownerCreated, $services];
        });

        [$propertyId, $ownerDclink, $ownerCreated, $services] = $result;

        return [
            'ok' => true,
            'mode' => 'property',
            'database' => $database,
            'property_id' => (int) $propertyId,
            'owner_dclink' => (int) $ownerDclink,
            'owner_created' => $ownerCreated,
            'area_linked' => $sageAreaId !== null,
            'services' => $services,
            'erf' => $erf,
        ];
    }

    /** Link to an existing Sage debtor with the same account, or create one. */
    private function resolveOwner(Customer $customer, string $account): array
    {
        $existing = DB::connection(self::CONN)->table('Client')->where('Account', $account)->value('DCLink');
        if ($existing !== null) {
            return [(int) $existing, false];
        }

        // Copy an existing debtor's classification so the new one is consistent.
        $template = DB::connection(self::CONN)->table('Client')
            ->whereNotNull('iClassID')
            ->first(['iClassID', 'iSettlementTermsID', 'iAgeingTermID', 'iAreasID', 'AccountTerms', 'iARPriceListNameID']);

        return [$this->createDebtor($customer, $account, $template, $template->iAreasID ?? null), true];
    }

    /**
     * Make the new property billable: create a `_ccg_EB_PropertyServices` link for
     * each service the O-Billing property subscribes to (mapped back to its Sage
     * tariff + service). Returns how many were created.
     */
    private function createPropertyServices(Customer $customer, int $propertyId, int $ownerDclink): int
    {
        $now = now();
        $count = 0;

        foreach ($customer->services as $service) {
            $tariffId = $this->tariffId($service->code);
            if ($tariffId === null) {
                continue;
            }
            $sageServiceId = DB::connection(self::CONN)->table('_ccg_EB_Tariffs')->where('ID', $tariffId)->value('ServiceID');
            if ($sageServiceId === null) {
                continue; // tariff not present in the target database
            }

            DB::connection(self::CONN)->table('_ccg_EB_PropertyServices')->insert([
                'PropertyID' => $propertyId,
                'CustomerID' => $ownerDclink,
                'ServiceID' => (int) $sageServiceId,
                'TariffID' => $tariffId,
                'Billable' => true,
                'UserCreated' => 'O-Billing',
                'DateCreated' => $now,
            ]);
            $count++;
        }

        return $count;
    }

    /** Recover the Sage tariff id from an imported service's code ("TRF-123" → 123). */
    private function tariffId(?string $code): ?int
    {
        return ($code !== null && str_starts_with($code, 'TRF-')) ? (int) substr($code, 4) : null;
    }

    /** The Sage area id behind an imported O-Billing area ("area:{id}" → {id}). */
    private function resolveAreaId(Customer $customer): ?int
    {
        $sageId = $customer->area?->sage_id;

        return ($sageId !== null && str_starts_with($sageId, 'area:'))
            ? (int) substr($sageId, 5)
            : null;
    }

    // ------------------------------------------------------------------
    // Debtors-ledger target (no property module)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function pushLedgerAccounts(Customer $customer, string $database, string $stand): array
    {
        // Each subscribed ledger service becomes one debtor account. The token is
        // recovered from the service type's import code ("LEDGER-{TOKEN}").
        $tokens = [];
        $unmapped = [];
        foreach ($customer->services as $service) {
            $code = (string) ($service->serviceType?->code ?? '');
            if (str_starts_with($code, 'LEDGER-')) {
                $tokens[substr($code, 7)] = true;
            } else {
                $unmapped[] = $service->displayName();
            }
        }

        if ($tokens === []) {
            $hint = $unmapped === []
                ? 'Subscribe it to at least one service first.'
                : 'None of its services ('.implode(', ', $unmapped).') is a Sage ledger account type — subscribe it to a ledger service (e.g. Development Levy, Licence).';

            return ['ok' => false, 'database' => $database,
                'error' => "This council keeps properties as one Sage debtor account per service, so the property needs a ledger service to be created from. {$hint}"];
        }

        $sageAreaId = $this->resolveWardId($customer);
        $currencyId = (int) (DB::connection(self::CONN)->table('Currency')
            ->where('CurrencyCode', $customer->currency)->value('CurrencyLink') ?? 1);

        $created = [];
        $existing = [];

        DB::connection(self::CONN)->transaction(function () use ($customer, $stand, $tokens, $sageAreaId, $currencyId, &$created, &$existing): void {
            foreach (array_keys($tokens) as $token) {
                $found = $this->existingLedgerAccount($stand, $token);
                if ($found !== null) {
                    $existing[] = $found;

                    continue;
                }

                $template = $this->ledgerTemplate($token);
                $account = $this->ledgerAccountCode($stand, $token, $template);

                $this->createDebtor($customer, $account, $template, $sageAreaId, $currencyId);
                $created[] = $account;
            }
        });

        if ($created === []) {
            return ['ok' => false, 'database' => $database,
                'error' => 'Already in Sage — account(s) '.implode(', ', $existing)." exist in {$database}."];
        }

        return [
            'ok' => true,
            'mode' => 'ledger',
            'database' => $database,
            'stand' => $stand,
            'created' => $created,
            'existing' => $existing,
            'unmapped' => $unmapped,
        ];
    }

    /** An existing `{stand}-{token}-…` account, if the ledger already has one. */
    private function existingLedgerAccount(string $stand, string $token): ?string
    {
        $prefix = $this->escapeLike("{$stand}-{$token}");

        $account = DB::connection(self::CONN)->table('Client')
            ->where(fn ($q) => $q
                ->where('Account', "{$stand}-{$token}")
                ->orWhere('Account', 'like', "{$prefix}-%"))
            ->value('Account');

        return $account !== null ? (string) $account : null;
    }

    /**
     * A same-token debtor to copy the class / terms / portion convention from.
     * Picks the token's dominant class so the new account bills like the rest
     * (the class drives the control account and invoice item during posting).
     */
    private function ledgerTemplate(string $token): ?object
    {
        $conn = DB::connection(self::CONN);
        $like = '%-'.$this->escapeLike($token).'-%';

        $classId = $conn->table('Client')
            ->where('Account', 'like', $like)
            ->whereNotNull('iClassID')
            ->groupBy('iClassID')
            ->orderByRaw('count(*) desc')
            ->value('iClassID');

        $query = $conn->table('Client')->whereNotNull('iClassID');
        if ($classId !== null) {
            $query->where('Account', 'like', $like)->where('iClassID', $classId);
        }

        return $query->first(['Account', 'iClassID', 'iSettlementTermsID', 'iAgeingTermID',
            'AccountTerms', 'iARPriceListNameID', 'RepID']);
    }

    /**
     * The new account's code, keeping the token's existing portion suffix
     * (e.g. "PLT006-ASSR-P1SP4" → new stands get "…-ASSR-P1SP4" too).
     */
    private function ledgerAccountCode(string $stand, string $token, ?object $template): string
    {
        $suffix = null;
        if ($template !== null) {
            $parts = explode('-', (string) $template->Account);
            $suffix = isset($parts[2]) ? implode('-', array_slice($parts, 2)) : null;
        }

        return implode('-', array_filter([$stand, $token, $suffix], fn ($p) => $p !== null && $p !== ''));
    }

    /** Insert a `Client` debtor for the customer and return its DCLink. */
    private function createDebtor(Customer $customer, string $account, ?object $template, ?int $sageAreaId, ?int $currencyId = null): int
    {
        $currencyId ??= (int) (DB::connection(self::CONN)->table('Currency')
            ->where('CurrencyCode', $customer->currency)->value('CurrencyLink') ?? 1);

        return (int) DB::connection(self::CONN)->table('Client')->insertGetId([
            'Account' => $account,
            'Name' => $customer->name,
            'Physical1' => $customer->address,
            'Telephone' => $customer->phone,
            'EMail' => $customer->email,
            'iCurrencyID' => $currencyId,
            'iClassID' => $template->iClassID ?? null,
            'iSettlementTermsID' => $template->iSettlementTermsID ?? 0,
            'iAgeingTermID' => $template->iAgeingTermID ?? 1,
            'iAreasID' => $sageAreaId,
            'AccountTerms' => $template->AccountTerms ?? 0,
            'iARPriceListNameID' => $template->iARPriceListNameID ?? null,
            'RepID' => $template->RepID ?? null,
            'UseEmail' => (bool) $customer->email,
        ], 'DCLink');
    }

    /** The Sage ward id behind a ledger-imported O-Billing area ("ward:{id}" → {id}). */
    private function resolveWardId(Customer $customer): ?int
    {
        $sageId = $customer->area?->sage_id;

        return ($sageId !== null && str_starts_with($sageId, 'ward:'))
            ? (int) substr($sageId, 5)
            : null;
    }

    /** Escape SQL LIKE wildcards in a literal fragment. */
    private function escapeLike(string $value): string
    {
        return str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $value);
    }
}
