<?php

declare(strict_types=1);

namespace App\Services\Sage;

use App\Models\Area;
use App\Models\AreaType;
use App\Models\BillingRun;
use App\Models\Invoice;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\ServiceType;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Tenancy\CurrentMunicipality;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Imports the master data from a Sage Evolution (CCG Municipal Billing) company
 * database — read through the `sage` connection — into O-Billing's own tables so
 * it appears in the normal Areas / Properties / Services / Tariffs / Billing Runs
 * screens and can be billed against.
 *
 * This targets the `_mtbl*` municipal-billing schema (Regions → SubRegions → Areas,
 * Properties → Portions → PortionServices, RateTariffs + Bands, BillingRuns +
 * RunDetails). Service *definitions* still live in the shared `_ccg_EB_Services`
 * table, so those are read from there.
 *
 * The mapping (Sage → O-Billing), with the deliberate simplifications flagged as
 * warnings at the end of a run:
 *   • _mtblRegions/_mtblSubRegions/_mtblAreas → the area tree (Area is the billing level)
 *   • _mtblProperties + _mtblPropertyPortions + Client → one Customer per portion
 *     (the billed consumer; account = Client.Account)
 *   • _ccg_EB_Services                 → ServiceType (billing basis from CalculationMethod)
 *   • _mtblRateTariffs                 → Service variant (taxable from its billing TrCode)
 *   • _mtblRateTariffBands             → the rate (block tariffs use their first tier)
 *   • per (area, service) actually used → a Tariff row, so billing is self-consistent
 *   • _mtblBillingRuns                 → BillingRun (metadata)
 *   • _mtblBillingRunDetails           → the invoices each run raised
 *
 * Re-running is safe and idempotent: areas key on a stable `sage_id`, customers on
 * their account number, services/types on a code, runs on their number; derived
 * tariffs and service subscriptions are rebuilt.
 */
final class SageImportService
{
    private const SAGE = 'sage';

    private int $municipalityId;

    /** sage area ID => O-Billing (billing-level) area ID. */
    private array $sageAreaToObilling = [];

    private ?int $fallbackAreaId = null;

    /** sage service ID => O-Billing service_type ID. */
    private array $serviceTypeMap = [];

    /** sage tariff ID => O-Billing service (variant) ID. */
    private array $serviceMap = [];

    /** sage tariff ID => ['rate','tr_code','effective_from','bands']. */
    private array $tariffBase = [];

    /** sage portion ID => O-Billing customer ID. */
    private array $portionCustomerId = [];

    /** sage portion ID => account number (for invoice numbers). */
    private array $portionAccount = [];

    /** sage portion-service ID => ['portion' => sage portion ID, 'tariff' => sage rate-tariff ID]. */
    private array $portionServiceMap = [];

    /** sage billing-run ID => ['id' => O-Billing run id, 'number' => ..., 'period' => Carbon]. */
    private array $runMap = [];

    /** Lookups loaded once from Sage. */
    private array $trCodes = [];      // id => object{Code,Tax}
    private array $periods = [];      // idPeriod => date string
    private array $currencies = [];   // CurrencyLink => code
    private array $categories = [];   // id => name

    private array $counts = [];

    private array $warnings = [];

    /**
     * @return array{counts: array<string,int>, warnings: list<string>, municipality: string}
     */
    public function run(): array
    {
        $muni = $this->importMunicipality();

        return app(CurrentMunicipality::class)->runFor($this->municipalityId, function () use ($muni): array {
            $this->loadLookups();

            DB::transaction(function (): void {
                $this->importAreas();
                $this->importServices();
                $this->importTariffs();
                $this->importCustomers();
                $this->importBillingRuns();
                $this->importInvoices();
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
            // The Sage company is named after its dataset ("… NCOA"); use the clean
            // council name, but never clobber a name the user has since set.
            'name' => $muni->name ?: config('sage.municipality.name'),
            'base_currency' => 'USD',
            'supported_currencies' => ['USD'],
            'tax_label' => 'VAT',
            'active' => true,
        ]);
        // Only set a default tax rate on first import; never clobber a value the
        // user has since configured. (Zimbabwe standard VAT is 15%.)
        if (! $muni->exists) {
            $muni->tax_rate = 0.15;
        }
        $muni->setup_completed_at ??= now();
        $muni->save();

        // Let every existing login reach this tenant.
        $muni->users()->syncWithoutDetaching(User::pluck('id')->all());

        $this->municipalityId = $muni->id;

        return $muni;
    }

    private function loadLookups(): void
    {
        $this->trCodes = DB::connection(self::SAGE)->table('TrCodes')
            ->select('idTrCodes', 'Code', 'Tax')->get()->keyBy('idTrCodes')->all();

        $this->periods = DB::connection(self::SAGE)->table('_etblPeriod')
            ->pluck('dPeriodDate', 'idPeriod')->all();

        $this->currencies = DB::connection(self::SAGE)->table('Currency')
            ->pluck('CurrencyCode', 'CurrencyLink')->all();

        $this->categories = DB::connection(self::SAGE)->table('_mtblCategories')
            ->pluck('cCategoryDescription', 'idCategory')->all();

        // The rate for each tariff = its lowest band (first tier for block tariffs),
        // ordered by the band's upper bound (fToValue).
        $bands = DB::connection(self::SAGE)->table('_mtblRateTariffBands')
            ->select('iRateTariffID', 'fToValue', 'fBandAmount', 'iFromPeriodID')
            ->orderBy('iRateTariffID')->orderBy('fToValue')->get();

        $bandCounts = [];
        foreach ($bands as $b) {
            $bandCounts[$b->iRateTariffID] = ($bandCounts[$b->iRateTariffID] ?? 0) + 1;
            if (isset($this->tariffBase[$b->iRateTariffID])) {
                continue; // already have the lowest band
            }
            $trCode = $this->trCodes[$this->tariffTrCode((int) $b->iRateTariffID)] ?? null;
            $periodDate = $this->periods[$b->iFromPeriodID] ?? null;
            $this->tariffBase[$b->iRateTariffID] = [
                'rate' => (float) $b->fBandAmount,
                'tr_code' => $trCode->Code ?? null,
                'effective_from' => $periodDate ? Carbon::parse($periodDate)->startOfMonth() : null,
            ];
        }

        $blockTariffs = count(array_filter($bandCounts, fn ($n) => $n > 1));
        if ($blockTariffs > 0) {
            $this->warnings[] = "{$blockTariffs} block/stepped tariff(s) (e.g. water consumption) were imported at their first-tier rate; see the Sage (Live) → Tariffs browser for every band.";
        }
    }

    /** Resolve a rate tariff's billing TrCode id (cached from the rate-tariffs table). */
    private function tariffTrCode(int $tariffId): int
    {
        static $map = null;
        $map ??= DB::connection(self::SAGE)->table('_mtblRateTariffs')
            ->pluck('iBillTrCodeID', 'idRateTariffs')->all();

        return (int) ($map[$tariffId] ?? 0);
    }

    private function importAreas(): void
    {
        $regionType = $this->areaType(1, 'Region', false);
        $subRegionType = $this->areaType(2, 'Sub-Region', false);
        $areaType = $this->areaType(3, 'Area', true);

        // Region(s) — the tree root.
        $rootId = null;
        foreach (DB::connection(self::SAGE)->table('_mtblRegions')->get() as $r) {
            $area = $this->upsertArea("region:{$r->idRegions}", $regionType->id, null, $r->cRegionDescription ?: $r->cRegion, $r->cRegion);
            $rootId ??= $area->id;
        }
        $rootId ??= $this->upsertArea('region:root', $regionType->id, null, config('sage.municipality.name'), null)->id;

        // Sub-region(s) under the root region. (The _mtbl schema scopes properties by
        // region/sub-region/area independently, so the tree is nested under the root.)
        $subRegionParent = $rootId;
        foreach (DB::connection(self::SAGE)->table('_mtblSubRegions')->get() as $d) {
            $area = $this->upsertArea("subregion:{$d->idSubRegions}", $subRegionType->id, $rootId, $d->cSubRegionDescription ?: $d->cSubRegion, $d->cSubRegion);
            $subRegionParent = $area->id;
        }

        // Billing-level areas (suburbs/wards).
        $areas = 0;
        foreach (DB::connection(self::SAGE)->table('_mtblAreas')->get() as $a) {
            $this->sageAreaToObilling[$a->idAreas] = $this->upsertArea(
                "area:{$a->idAreas}", $areaType->id, $subRegionParent, $a->cAreaDescription ?: $a->cArea, $a->cArea
            )->id;
            $areas++;
        }

        // Catch-all for the handful of properties without an area.
        $this->fallbackAreaId = $this->upsertArea('area:unknown', $areaType->id, $subRegionParent, '(Unknown Area)', null)->id;

        $this->counts['areas'] = $areas;
    }

    private function areaType(int $level, string $name, bool $billing): AreaType
    {
        $type = AreaType::firstOrNew(['municipality_id' => $this->municipalityId, 'level' => $level]);
        $type->fill(['name' => $name, 'is_billing_level' => $billing])->save();

        return $type;
    }

    private function upsertArea(string $sageKey, int $typeId, ?int $parentId, string $name, ?string $code): Area
    {
        $area = Area::firstOrNew(['municipality_id' => $this->municipalityId, 'sage_id' => $sageKey]);
        $area->fill([
            'area_type_id' => $typeId,
            'parent_id' => $parentId,
            'name' => $name,
            'code' => $code,
        ])->save();

        return $area;
    }

    private function importServices(): void
    {
        // Service *definitions* still live in the shared _ccg_EB_Services table; the
        // _mtbl schema has no service-groups table, so billing basis is derived from
        // the calculation method and the service name alone.

        // Sage Service → O-Billing ServiceType (carries the billing basis).
        foreach (DB::connection(self::SAGE)->table('_ccg_EB_Services')->get() as $s) {
            $type = ServiceType::firstOrNew(['municipality_id' => $this->municipalityId, 'code' => "SVC-{$s->ID}"]);
            $type->fill([
                'name' => $s->Name,
                'billing_basis' => $this->mapBasis((int) $s->CalculationMethod, '', (string) $s->Name),
                'unit_label' => $s->MeasurableUnit ?: null,
                'active' => true,
            ])->save();
            $this->serviceTypeMap[$s->ID] = $type->id;
        }

        // Sage Rate Tariff → O-Billing Service (priced variant under its type).
        $variantsByType = [];
        foreach (DB::connection(self::SAGE)->table('_mtblRateTariffs')->get() as $t) {
            $typeId = $this->serviceTypeMap[$t->iRateTariffServiceID] ?? null;
            $name = $t->cRateTariffDescription ?: $t->cRateTariff;
            if ($typeId === null) {
                $this->warnings[] = "Rate tariff '{$name}' skipped: its Sage service #{$t->iRateTariffServiceID} was not found.";

                continue;
            }
            $trCode = $this->trCodes[(int) $t->iBillTrCodeID] ?? null;
            $service = Service::firstOrNew(['municipality_id' => $this->municipalityId, 'code' => "TRF-{$t->idRateTariffs}"]);
            $service->fill([
                'service_type_id' => $typeId,
                'name' => $name,
                'taxable' => $trCode ? (bool) $trCode->Tax : true,
                'is_default' => false,
                'active' => true,
            ])->save();
            $this->serviceMap[$t->idRateTariffs] = $service->id;
            $variantsByType[$typeId] = true;
        }

        // A service type with no tariff still needs a billable variant.
        foreach ($this->serviceTypeMap as $typeId) {
            if (! isset($variantsByType[$typeId])) {
                ServiceType::find($typeId)?->ensureDefaultService();
            }
        }

        $this->counts['service_types'] = count($this->serviceTypeMap);
        $this->counts['services'] = count($this->serviceMap);
    }

    private function mapBasis(int $calc, string $group, string $name): string
    {
        if ($calc === 2) {
            return ServiceType::BASIS_PER_UNIT; // metered / consumption (e.g. water in KL)
        }

        $g = strtolower($group);
        $n = strtolower($name);
        if (str_contains($g, 'assessment') || str_contains($g, 'rate') || str_starts_with($n, 'rates')) {
            return ServiceType::BASIS_PER_PROPERTY_VALUE; // assessment rates on the property value
        }

        return ServiceType::BASIS_FLAT;
    }

    /**
     * Materialise an O-Billing tariff for every (area, service) actually billed in
     * Sage, so each imported customer's services resolve to a rate. Fully derived,
     * so it is wiped and rebuilt on every run.
     */
    private function importTariffs(): void
    {
        Tariff::where('municipality_id', $this->municipalityId)->delete();

        $combos = DB::connection(self::SAGE)->table('_mtblPropertyPortionServices as ps')
            ->join('_mtblPropertyPortions as pp', 'pp.idPropertyPortions', '=', 'ps.iPropertyPortionID')
            ->join('_mtblProperties as p', 'p.idProperty', '=', 'pp.iPortionPropertyID')
            ->where('ps.bBillable', 1)->whereNotNull('ps.iServiceRateTariffID')
            ->select('p.iPropertyAreaID as AreaID', 'ps.iServiceRateTariffID as TariffID')->distinct()->get();

        $now = now();
        $rows = [];
        foreach ($combos as $c) {
            $serviceId = $this->serviceMap[$c->TariffID] ?? null;
            if ($serviceId === null) {
                continue;
            }
            $areaId = $c->AreaID !== null
                ? ($this->sageAreaToObilling[$c->AreaID] ?? $this->fallbackAreaId)
                : $this->fallbackAreaId;
            $base = $this->tariffBase[$c->TariffID] ?? ['rate' => 0.0, 'tr_code' => null, 'effective_from' => null];

            $rows[] = [
                'municipality_id' => $this->municipalityId,
                'area_id' => $areaId,
                'service_id' => $serviceId,
                'rate' => $base['rate'],
                'currency' => 'USD',
                'tr_code' => $base['tr_code'],
                'effective_from' => $base['effective_from'],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tariffs')->insert($chunk);
        }

        $this->counts['tariffs'] = count($rows);
        $this->warnings[] = 'Sage tariffs are priced per property category, not per suburb; O-Billing tariffs were created for every (area, service) combination actually billed, all in USD.';
    }

    private function importCustomers(): void
    {
        // A customer is a billed consumer on a property portion. Portion carries the
        // consumer (→ Client account) and its own valuation; the property carries the
        // area and address.
        $portions = DB::connection(self::SAGE)->table('_mtblPropertyPortions as pp')
            ->join('_mtblProperties as p', 'p.idProperty', '=', 'pp.iPortionPropertyID')
            ->leftJoin('Client as c', 'c.DCLink', '=', 'pp.iPortionConsumerID')
            ->select(
                'pp.idPropertyPortions as PortionID', 'pp.iPortionRatingID', 'pp.iPortionUsageID',
                'pp.fPortionSize', 'pp.fPortionLandValue', 'pp.fPortionImprovementValue',
                'p.iPropertyAreaID as AreaID', 'p.fLandSize', 'p.fLandValue', 'p.fImprovementValue',
                'p.cAddress1', 'p.cAddress2', 'p.cAddress3', 'p.cAddress4', 'p.cAddress5',
                'c.Account', 'c.Name', 'c.EMail', 'c.Telephone',
                'c.Physical1', 'c.Physical2', 'c.Physical3', 'c.Physical4', 'c.Physical5', 'c.iCurrencyID'
            )->get();

        $now = now();
        $rows = [];
        $usedAccounts = [];
        $portionAccount = [];
        foreach ($portions as $p) {
            $account = trim((string) $p->Account) ?: ('PORT-'.$p->PortionID);
            if (isset($usedAccounts[$account])) {
                $account .= '-'.$p->PortionID; // one client can hold several portions
            }
            $usedAccounts[$account] = true;
            $portionAccount[$p->PortionID] = $account;

            $areaId = $p->AreaID !== null
                ? ($this->sageAreaToObilling[$p->AreaID] ?? $this->fallbackAreaId)
                : $this->fallbackAreaId;

            $catName = $this->categories[$p->iPortionRatingID] ?? $this->categories[$p->iPortionUsageID] ?? '';
            $address = $this->joinNonEmpty([
                $p->cAddress1, $p->cAddress2, $p->cAddress3, $p->cAddress4, $p->cAddress5,
            ]) ?: $this->joinNonEmpty([$p->Physical1, $p->Physical2, $p->Physical3, $p->Physical4, $p->Physical5]);

            // Prefer the portion's own valuation, falling back to the property's.
            $landValue = $this->num($p->fPortionLandValue) ?? $this->num($p->fLandValue);
            $improvementValue = $this->num($p->fPortionImprovementValue) ?? $this->num($p->fImprovementValue);
            $propertyValue = ($landValue ?? 0) + ($improvementValue ?? 0) ?: null;

            $rows[] = [
                'municipality_id' => $this->municipalityId,
                'area_id' => $areaId,
                'account_number' => $account,
                'name' => trim((string) $p->Name) ?: '(No name)',
                'type' => $this->mapCustomerType($catName),
                'email' => $p->EMail ?: null,
                'phone' => $p->Telephone ?: null,
                'address' => $address ?: null,
                'property_value' => $propertyValue,
                'land_size' => $this->num($p->fPortionSize) ?? $this->num($p->fLandSize),
                'land_value' => $landValue,
                'improvement_value' => $improvementValue,
                'currency' => $this->currencies[$p->iCurrencyID] ?? 'USD',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('customers')->upsert(
                $chunk,
                ['municipality_id', 'account_number'],
                ['area_id', 'name', 'type', 'email', 'phone', 'address', 'property_value',
                    'land_size', 'land_value', 'improvement_value', 'currency', 'active', 'updated_at'],
            );
        }

        $accountId = DB::table('customers')->where('municipality_id', $this->municipalityId)
            ->pluck('id', 'account_number');

        $this->counts['customers'] = $accountId->count();

        // Remember portion → customer / account for the invoice import.
        $this->portionAccount = $portionAccount;
        foreach ($portionAccount as $portionId => $account) {
            $this->portionCustomerId[$portionId] = $accountId[$account] ?? null;
        }

        $this->importSubscriptions($portionAccount, $accountId, $now);
    }

    /** Rebuild the customer↔service links from Sage's billable portion services. */
    private function importSubscriptions(array $portionAccount, \Illuminate\Support\Collection $accountId, Carbon $now): void
    {
        $ps = DB::connection(self::SAGE)->table('_mtblPropertyPortionServices')
            ->where('bBillable', 1)->whereNotNull('iServiceRateTariffID')
            ->select('idPropertyPortionServices', 'iPropertyPortionID', 'iServiceRateTariffID')->get();

        $pivot = [];
        $seen = [];
        foreach ($ps as $row) {
            // Remember the portion-service → portion / tariff chain for invoice import.
            $this->portionServiceMap[$row->idPropertyPortionServices] = [
                'portion' => $row->iPropertyPortionID,
                'tariff' => $row->iServiceRateTariffID,
            ];

            $account = $portionAccount[$row->iPropertyPortionID] ?? null;
            $customerId = $account !== null ? ($accountId[$account] ?? null) : null;
            $serviceId = $this->serviceMap[$row->iServiceRateTariffID] ?? null;
            if ($customerId === null || $serviceId === null) {
                continue;
            }
            $key = $customerId.'-'.$serviceId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pivot[] = ['customer_id' => $customerId, 'service_id' => $serviceId, 'created_at' => $now, 'updated_at' => $now];
        }

        // Clear existing links for these customers, then insert the fresh set.
        foreach (array_chunk($accountId->values()->all(), 500) as $chunk) {
            DB::table('customer_service')->whereIn('customer_id', $chunk)->delete();
        }
        foreach (array_chunk($pivot, 1000) as $chunk) {
            DB::table('customer_service')->insert($chunk);
        }

        $this->counts['subscriptions'] = count($pivot);
    }

    private function mapCustomerType(string $category): string
    {
        $c = strtolower($category);

        return match (true) {
            str_contains($c, 'gov') => 'government',
            str_contains($c, 'residential'), str_contains($c, 'flat') => 'residential',
            str_contains($c, 'commercial'), str_contains($c, 'industr'),
            str_contains($c, 'service'), str_contains($c, 'shop'), str_contains($c, 'business') => 'business',
            str_contains($c, 'church'), str_contains($c, 'school'), str_contains($c, 'hospital'),
            str_contains($c, 'institution'), str_contains($c, 'clinic'), str_contains($c, 'creche') => 'government',
            default => 'residential',
        };
    }

    private function importBillingRuns(): void
    {
        $count = 0;
        foreach (DB::connection(self::SAGE)->table('_mtblBillingRuns')->get() as $r) {
            $periodDate = $this->periods[$r->iBillingRunPeriodID] ?? null;
            $periodMonth = $periodDate ? Carbon::parse($periodDate)->startOfMonth() : now()->startOfMonth();
            $number = $r->cBillingRunNumber ?: ('RUN-'.$r->idBillingRun);
            $run = BillingRun::firstOrNew(['municipality_id' => $this->municipalityId, 'run_number' => $number]);
            $run->fill([
                'period_month' => $periodMonth,
                'frequency' => $this->mapFrequency($r),
                'description' => 'Imported from Sage',
                'status' => $r->bBillingRunProcessed ? 'completed' : 'draft',
                'run_at' => $r->iSysDateProcessed ? Carbon::parse($r->iSysDateProcessed) : null,
                'invoice_count' => 0,
            ])->save();
            $this->runMap[$r->idBillingRun] = ['id' => $run->id, 'number' => $number, 'period' => $periodMonth];
            $count++;
        }

        $this->counts['billing_runs'] = $count;
    }

    /** Map a Sage billing run's rate-frequency flags to an O-Billing frequency. */
    private function mapFrequency(object $r): string
    {
        return match (true) {
            (bool) ($r->bBillAnnuallyRates ?? false) => 'annually',
            (bool) ($r->bBillQuarterlyRates ?? false) => 'quarterly',
            default => 'monthly',
        };
    }

    /**
     * Import the invoices each billing run raised. Sage keeps one billing-run detail
     * per portion-service line, so we resolve each line back to its portion (the
     * customer) and group them by portion within a run — each group is that
     * customer's invoice for the period — copying the real billed amounts into
     * O-Billing's invoices + invoice_lines. Fully Sage-derived, so wiped and rebuilt
     * on every run. (Run details carry no currency/date, so the run's period is used
     * and amounts are treated as the municipality's base currency.)
     */
    private function importInvoices(): void
    {
        @ini_set('memory_limit', '1024M');

        Invoice::where('municipality_id', $this->municipalityId)->delete();

        $now = now();
        $totalInvoices = 0;
        $totalLines = 0;

        foreach ($this->runMap as $sageRunId => $run) {
            $details = DB::connection(self::SAGE)->table('_mtblBillingRunDetails')
                ->where('iBillingRunID', $sageRunId)
                ->select('iPropertyPortionServiceID', 'cDescription', 'fUnits',
                    'fExclusive', 'fTaxAmount', 'fInclusive')
                ->get();

            // Resolve each detail's owning portion up front so we can group by it.
            $byPortion = [];
            foreach ($details as $d) {
                $ps = $this->portionServiceMap[$d->iPropertyPortionServiceID] ?? null;
                if ($ps === null) {
                    continue; // line on a portion-service we didn't import
                }
                $byPortion[$ps['portion']][] = [$d, $ps['tariff']];
            }

            $headers = [];          // invoice_number => header row
            $linesByInvoice = [];   // invoice_number => list of line rows
            $runTotal = 0.0;

            foreach ($byPortion as $portionId => $rows) {
                $customerId = $this->portionCustomerId[$portionId] ?? null;
                if ($customerId === null) {
                    continue; // charge on a portion we didn't import
                }
                $invoiceNumber = $run['number'].'-'.($this->portionAccount[$portionId] ?? $portionId);

                $subtotal = $tax = $total = 0.0;
                $currency = 'USD';
                $issuedAt = null;
                $lines = [];
                foreach ($rows as [$t, $sageTariff]) {
                    $units = (float) $t->fUnits;
                    $exclusive = (float) $t->fExclusive;
                    $subtotal += $exclusive;
                    $tax += (float) $t->fTaxAmount;
                    $total += (float) $t->fInclusive;
                    $base = $this->tariffBase[$sageTariff] ?? null;
                    $lines[] = [
                        'service_id' => $this->serviceMap[$sageTariff] ?? null,
                        'tr_code' => $base['tr_code'] ?? null,
                        'description' => mb_substr(trim((string) $t->cDescription), 0, 255) ?: 'Charge',
                        'quantity' => $units,
                        'unit_amount' => $units != 0.0 ? round($exclusive / $units, 4) : $exclusive,
                        'amount' => $exclusive,
                        'tax_amount' => (float) $t->fTaxAmount,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $headers[$invoiceNumber] = [
                    'municipality_id' => $this->municipalityId,
                    'billing_run_id' => $run['id'],
                    'customer_id' => $customerId,
                    'invoice_number' => $invoiceNumber,
                    'period_month' => $run['period'],
                    'currency' => $currency,
                    'subtotal' => round($subtotal, 2),
                    'tax_total' => round($tax, 2),
                    'total' => round($total, 2),
                    'status' => 'issued',
                    'issued_at' => $issuedAt ?? $run['period'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $linesByInvoice[$invoiceNumber] = $lines;
                $runTotal += $total;
            }

            foreach (array_chunk(array_values($headers), 500) as $chunk) {
                DB::table('invoices')->insert($chunk);
            }

            // Re-attach the freshly-inserted invoice ids to their lines.
            $idByNumber = DB::table('invoices')->where('billing_run_id', $run['id'])
                ->pluck('id', 'invoice_number');
            $lineRows = [];
            foreach ($linesByInvoice as $invoiceNumber => $lines) {
                $invoiceId = $idByNumber[$invoiceNumber] ?? null;
                if ($invoiceId === null) {
                    continue;
                }
                foreach ($lines as $line) {
                    $line['invoice_id'] = $invoiceId;
                    $lineRows[] = $line;
                }
            }
            foreach (array_chunk($lineRows, 1000) as $chunk) {
                DB::table('invoice_lines')->insert($chunk);
            }

            BillingRun::find($run['id'])?->update([
                'invoice_count' => count($headers),
                'currency_totals' => ['USD' => round($runTotal, 2)],
            ]);

            $totalInvoices += count($headers);
            $totalLines += count($lineRows);
        }

        $this->counts['invoices'] = $totalInvoices;
        $this->counts['invoice_lines'] = $totalLines;
    }

    private function joinNonEmpty(array $parts): string
    {
        return collect($parts)->map(fn ($v) => trim((string) $v))->filter()->implode(', ');
    }

    private function num(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }
}
