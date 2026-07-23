<?php

declare(strict_types=1);

namespace App\Services\Sage;

use App\Models\Municipality;
use App\Models\ServiceType;
use App\Support\Tenancy\CurrentMunicipality;
use Illuminate\Support\Facades\DB;

/**
 * Prices every imported ratepayer service from the Sage billables (the
 * inventory service items visible under Report → Inventory → Prices), using
 * each debtor's CLIENT CLASS to pick the right rate variant.
 *
 * The council encodes the variant taxonomy twice in Sage:
 *
 *   • client classes (`CliClass`) classify every debtor account, e.g.
 *     `LEA-R-H-200m2-P3SP3`, `ASS R-RES-MED-P3SP3`, `REF-COMM-TWN-P2SP1`
 *   • billable items (`StkItem` + USD price list) carry the prices, e.g.
 *     `LEA-R-H-200m2-P3SP3` $15, `P3SP3-ASS RATE004` "Medium communal" $2
 *
 * Where the class code IS the item code (leases) the match is exact; otherwise
 * the class is matched to a billable of the same service family by scoring
 * shared words in their descriptions. The resolved price is written to the
 * `customer_service.amount` per-client override — the same mechanism as the
 * charge-workbook import — which the billing engine prefers over ward tariffs.
 *
 * Idempotent: re-running replaces the stored amounts. Unmatched classes and
 * zero-priced billables are reported so the council can fix them in Sage.
 */
final class SagePriceImportService
{
    private const SAGE = 'sage';

    /**
     * Billing cadence per account-type token. DELIBERATE ASSUMPTION (mirrors
     * the earlier imports): rates/licences/levies/leases bill annually, the
     * utility-style charges monthly. Adjust and re-run to change.
     */
    private const TOKEN_FREQUENCIES = [
        'ASS' => 'annually',
        'AS' => 'annually',
        'LIC' => 'annually',
        'LDL' => 'annually',
        'LEA' => 'annually',
        'PTX' => 'annually',
        'REF' => 'monthly',
        'SEW' => 'monthly',
        'RNT' => 'monthly',
    ];

    /** Class-code family prefix => account-type token family. */
    private const FAMILY_ALIASES = [
        'SEWER' => 'SEW',
        'PRP' => 'PTX',
    ];

    /** Words too generic to identify a rate variant when scoring descriptions. */
    private const STOPWORDS = [
        'binga', 'areas', 'area', 'fee', 'fees', 'charge', 'charges', 'the',
        'and', 'per', 'rate', 'rates', 'property', 'collection',
    ];

    /**
     * The two taxonomies spell the same concept differently (class
     * "REF-COMM-TWN" vs billable "Refuse collection-Businesss areas").
     * Map every spelling to one canonical token before scoring.
     */
    private const SYNONYMS = [
        'assesment' => 'ASS', 'assessnt' => 'ASS', 'assessment' => 'ASS', 'assmnt' => 'ASS', 'ass' => 'ASS',
        'residential' => 'RES', 'reside' => 'RES', 'res' => 'RES',
        'medium' => 'MED', 'med' => 'MED',
        'high' => 'HIGH', 'low' => 'LOW',
        'commercial' => 'BIZ', 'comm' => 'BIZ', 'business' => 'BIZ', 'businesss' => 'BIZ', 'dealer' => 'BIZ', 'dea' => 'BIZ',
        'communal' => 'RURAL', 'rural' => 'RURAL', 'rur' => 'RURAL',
        'urban' => 'URBAN', 'town' => 'URBAN', 'twn' => 'URBAN',
        'stateland' => 'STATE',
        'institutions' => 'INST', 'institution' => 'INST', 'inst' => 'INST',
        'church' => 'CHURCH', 'churches' => 'CHURCH', 'chrc' => 'CHURCH',
        'lodge' => 'LODGE', 'lodges' => 'LODGE', 'lodg' => 'LODGE',
        'industrial' => 'IND', 'ind' => 'IND',
        'licence' => 'LIC', 'licences' => 'LIC', 'lic' => 'LIC',
        'refuse' => 'REF', 'ref' => 'REF',
        'sewer' => 'SEW', 'sewerage' => 'SEW', 'sewarage' => 'SEW', 'sew' => 'SEW',
    ];

    private int $municipalityId;

    private array $warnings = [];

    /** @var array<string, array{price: float, via: string, item: string}> class code => resolution */
    private array $classPrices = [];

    /** @var list<array{code: string, desc: string, clients: int}> */
    private array $unmatchedClasses = [];

    /**
     * @return array{municipality: string, lines: list<array<string,mixed>>, unmatched: list<array{code:string,desc:string,clients:int}>, warnings: list<string>, dry_run: bool}
     */
    public function run(bool $dryRun = false): array
    {
        $muni = Municipality::where('code', config('sage.municipality.code'))->first();
        if ($muni === null) {
            throw new \RuntimeException('Run sage:import-ledger first — the municipality does not exist yet.');
        }
        $this->municipalityId = $muni->id;

        return app(CurrentMunicipality::class)->runFor($muni->id, function () use ($muni, $dryRun): array {
            $items = $this->billableItems();
            $classes = $this->clientClasses();
            $this->resolveClassPrices($classes, $items);

            $perToken = $this->priceSubscriptions($dryRun);

            return [
                'municipality' => $muni->name,
                'lines' => $perToken,
                'unmatched' => $this->unmatchedClasses,
                'warnings' => $this->warnings,
                'dry_run' => $dryRun,
            ];
        });
    }

    // ------------------------------------------------------------------
    //  Sage reads
    // ------------------------------------------------------------------

    /**
     * The billables with their USD price: [{code, desc, price, family}].
     *
     * @return list<array{code: string, desc: string, price: float, family: ?string}>
     */
    private function billableItems(): array
    {
        $priceListId = DB::connection(self::SAGE)->table('_etblPriceListName')
            ->where('cName', 'like', '%USD%')
            ->value('IDPriceListName');

        if ($priceListId === null) {
            $priceListId = DB::connection(self::SAGE)->table('_etblPriceListName')
                ->where('bDefault', 1)->value('IDPriceListName');
            $this->warnings[] = 'No "USD" price list found in Sage — fell back to the default price list.';
        }

        return DB::connection(self::SAGE)->table('StkItem as s')
            ->join('_etblPriceListPrices as p', function ($join) use ($priceListId): void {
                $join->on('p.iStockID', '=', 's.StockLink')
                    ->where('p.iPriceListNameID', '=', $priceListId);
            })
            ->get(['s.Code', 's.Description_1', 'p.fExclPrice'])
            ->map(fn ($r) => [
                'code' => trim((string) $r->Code),
                'desc' => trim((string) $r->Description_1),
                'price' => round((float) $r->fExclPrice, 2),
                'family' => $this->family(trim((string) $r->Code).' '.$r->Description_1),
            ])
            ->all();
    }

    /** @return list<object{IdCliClass: int, Code: string, Description: string}> */
    private function clientClasses(): array
    {
        return DB::connection(self::SAGE)->table('CliClass')
            ->get(['IdCliClass', 'Code', 'Description'])
            ->all();
    }

    // ------------------------------------------------------------------
    //  Class → billable price resolution
    // ------------------------------------------------------------------

    /**
     * Public for testability: the matching logic runs offline on plain arrays.
     *
     * @param  list<object>  $classes
     * @param  list<array{code: string, desc: string, price: float, family: ?string}>  $items
     * @return array<string, array{price: float, via: string, item: string}>
     */
    public function resolveClassPrices(array $classes, array $items): array
    {
        $byCode = [];
        foreach ($items as $item) {
            $byCode[strtoupper($item['code'])] = $item;
        }

        foreach ($classes as $class) {
            $code = strtoupper(trim((string) $class->Code));
            if ($code === '') {
                continue;
            }

            // 1. The class code IS a billable item code (the lease taxonomy).
            $exact = $byCode[$code] ?? null;
            if ($exact !== null && $exact['price'] > 0) {
                $this->classPrices[$code] = ['price' => $exact['price'], 'via' => 'exact', 'item' => $exact['code']];

                continue;
            }

            // 2. Same service family, best description-word overlap.
            $match = $this->bestDescriptionMatch($class, $items);
            if ($match !== null) {
                $this->classPrices[$code] = $match;
            }
        }

        return $this->classPrices;
    }

    /**
     * Public for testability. Categorises a billable item's code+description
     * into its service family, as billableItems() does for live rows.
     *
     * @return array{code: string, desc: string, price: float, family: ?string}
     */
    public function makeItem(string $code, string $desc, float $price): array
    {
        return ['code' => $code, 'desc' => $desc, 'price' => $price, 'family' => $this->family($code.' '.$desc)];
    }

    /**
     * @param  list<array{code: string, desc: string, price: float, family: ?string}>  $items
     * @return array{price: float, via: string, item: string}|null
     */
    private function bestDescriptionMatch(object $class, array $items): ?array
    {
        $family = $this->family((string) $class->Code);
        if ($family === null) {
            return null;
        }

        $classWords = $this->significantWords((string) $class->Code.' '.$class->Description);
        if ($classWords === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($items as $item) {
            if ($item['family'] !== $family || $item['price'] <= 0) {
                continue;
            }

            $score = count(array_intersect($classWords, $this->significantWords($item['code'].' '.$item['desc'])));

            // Ties: take the LOWER price — the conservative choice when the data
            // cannot distinguish variants (e.g. communal vs stateland rates, or
            // a recurring charge vs its once-off connection fee).
            $tieBreak = $score === $bestScore && $best !== null && $item['price'] < $best['price'];

            if ($score > $bestScore || $tieBreak) {
                $bestScore = $score;
                $best = ['price' => $item['price'], 'via' => 'matched', 'item' => $item['code']];
            }
        }

        if ($best === null || $bestScore < 2) {
            return null; // not confident enough — reported as unmatched
        }

        return $best;
    }

    /** The service-family token at the start of a class/item identifier. */
    private function family(string $text): ?string
    {
        // Item codes look like "P3SP3-ASS RATE004"; class codes like
        // "ASS R-RES-MED-P3SP3". Strip the project segments (P1SP4 …), then take
        // the first alphabetic run and normalise known aliases.
        $stripped = preg_replace('/P\d+(SP\d+)?/i', ' ', strtoupper($text)) ?? '';

        foreach (preg_split('/[^A-Z]+/', $stripped) ?: [] as $word) {
            if (strlen($word) >= 2) {
                return self::FAMILY_ALIASES[$word] ?? $word;
            }
        }

        return null;
    }

    /**
     * Canonical tokens identifying a rate variant (density band, category,
     * urban/communal, size). Project segments and generic filler are dropped;
     * known synonyms collapse to one spelling, the rest stem to 4 characters.
     *
     * @return list<string>
     */
    private function significantWords(string $text): array
    {
        $stripped = preg_replace('/p\d+(sp\d+)?/i', ' ', strtolower($text)) ?? '';

        $words = [];
        foreach (preg_split('/[^a-z0-9]+/', $stripped) ?: [] as $word) {
            if (isset(self::SYNONYMS[$word])) {
                $words[] = self::SYNONYMS[$word];

                continue;
            }
            if (strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $words[] = substr($word, 0, 4);
        }

        return array_values(array_unique($words));
    }

    // ------------------------------------------------------------------
    //  O-Billing writes
    // ------------------------------------------------------------------

    /**
     * Walk the Sage debtors, resolve each account's class price and store it on
     * the matching customer↔service subscription.
     *
     * @return list<array<string,mixed>> per-token report lines
     */
    private function priceSubscriptions(bool $dryRun): array
    {
        $classById = [];
        foreach ($this->clientClasses() as $class) {
            $classById[(int) $class->IdCliClass] = strtoupper(trim((string) $class->Code));
        }

        $customers = DB::table('customers')
            ->where('municipality_id', $this->municipalityId)
            ->pluck('id', 'account_number');

        $services = DB::table('services')
            ->join('service_types', 'service_types.id', '=', 'services.service_type_id')
            ->where('services.municipality_id', $this->municipalityId)
            ->where('service_types.code', 'like', 'LEDGER-%')
            ->pluck('services.id', 'service_types.code');

        // amount per (customer, service); multiple Sage accounts for the same
        // stand+token (portions) accumulate.
        $amounts = [];
        $stats = [];
        $unmatchedClients = [];

        $rows = DB::connection(self::SAGE)->table('Client')->get(['Account', 'iClassID']);
        foreach ($rows as $row) {
            [$stand, $token] = $this->splitAccount((string) $row->Account);
            if ($stand === '' || $token === '(other)') {
                continue;
            }

            $st = &$stats[$token];
            $st ??= ['subs' => 0, 'priced' => 0, 'unpriced' => 0, 'total' => 0.0, 'min' => null, 'max' => null];
            $st['subs']++;

            $customerId = $customers[$stand] ?? null;
            $serviceId = $services["LEDGER-{$token}"] ?? null;
            $classCode = $classById[(int) $row->iClassID] ?? null;
            $resolved = $classCode !== null ? ($this->classPrices[$classCode] ?? null) : null;

            if ($customerId === null || $serviceId === null || $resolved === null) {
                $st['unpriced']++;
                if ($classCode !== null && $resolved === null) {
                    $unmatchedClients[$classCode] = ($unmatchedClients[$classCode] ?? 0) + 1;
                }
                unset($st);

                continue;
            }

            $key = "{$customerId}|{$serviceId}";
            $amounts[$key] = ($amounts[$key] ?? 0.0) + $resolved['price'];

            $st['priced']++;
            $st['total'] += $resolved['price'];
            $st['min'] = $st['min'] === null ? $resolved['price'] : min($st['min'], $resolved['price']);
            $st['max'] = $st['max'] === null ? $resolved['price'] : max($st['max'], $resolved['price']);
            unset($st);
        }

        if (! $dryRun) {
            DB::transaction(function () use ($amounts, $stats): void {
                $now = now();
                foreach ($amounts as $key => $amount) {
                    [$customerId, $serviceId] = array_map('intval', explode('|', $key));
                    DB::table('customer_service')->updateOrInsert(
                        ['customer_id' => $customerId, 'service_id' => $serviceId],
                        ['amount' => round($amount, 2), 'updated_at' => $now, 'created_at' => $now],
                    );
                }

                // Record each priced token's billing cadence so an annual charge
                // can never be raised by a monthly run.
                foreach (array_keys($stats) as $token) {
                    $frequency = self::TOKEN_FREQUENCIES[$token] ?? null;
                    if ($frequency !== null) {
                        ServiceType::where('municipality_id', $this->municipalityId)
                            ->where('code', "LEDGER-{$token}")
                            ->update(['default_frequency' => $frequency]);
                    }
                }
            });
        }

        // Unmatched classes, worst first, for the report.
        $classDescriptions = [];
        foreach ($this->clientClasses() as $class) {
            $classDescriptions[strtoupper(trim((string) $class->Code))] = trim((string) $class->Description);
        }
        arsort($unmatchedClients);
        foreach ($unmatchedClients as $code => $count) {
            $this->unmatchedClasses[] = ['code' => $code, 'desc' => $classDescriptions[$code] ?? '', 'clients' => $count];
        }

        $this->warnings[] = 'Cadence assumption: rates, licences, levies and leases bill ANNUALLY; refuse, sewer and rentals bill MONTHLY. Adjust TOKEN_FREQUENCIES in SagePriceImportService and re-run if the council bills differently.';
        $this->warnings[] = 'Where a class matched two billables equally (e.g. communal vs stateland rates), the LOWER price was chosen as the conservative option — review and correct individual amounts in the panel if needed.';

        // Report lines, largest token first.
        uasort($stats, fn ($a, $b) => $b['subs'] <=> $a['subs']);
        $lines = [];
        foreach ($stats as $token => $s) {
            $lines[] = [
                'token' => $token,
                'frequency' => self::TOKEN_FREQUENCIES[$token] ?? '—',
                'subs' => $s['subs'],
                'priced' => $s['priced'],
                'unpriced' => $s['unpriced'],
                'min' => $s['min'],
                'max' => $s['max'],
                'avg' => $s['priced'] > 0 ? $s['total'] / $s['priced'] : null,
            ];
        }

        return $lines;
    }

    /**
     * Stand prefix + account-type token, identical to the ledger importer: the
     * token is the second-to-last hyphen-separated segment.
     *
     * @return array{0: string, 1: string}
     */
    private function splitAccount(string $account): array
    {
        $parts = array_map('trim', explode('-', $account));
        $count = count($parts);

        if ($count < 3) {
            return [$parts[0], $count === 2 ? (strtoupper($parts[1]) ?: '(other)') : '(other)'];
        }

        $token = strtoupper($parts[$count - 2]);
        $prefix = implode('-', array_slice($parts, 0, $count - 2));

        return [$prefix, $token ?: '(other)'];
    }
}
