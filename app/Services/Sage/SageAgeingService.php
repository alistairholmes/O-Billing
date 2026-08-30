<?php

declare(strict_types=1);

namespace App\Services\Sage;

use App\Models\AgeingRow;
use App\Models\AgeingSnapshot;
use App\Models\Municipality;
use App\Models\ServiceType;
use App\Support\Sage\LedgerAccount;
use App\Support\Tenancy\CurrentMunicipality;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Captures the council's debtor ageing from Sage into an O-Billing snapshot.
 *
 * O-Billing raises invoices but never sees the receipts that settle them —
 * payments are taken in Sage — so "what is still owed" only exists there.
 * Sage keeps it per transaction as `PostAR.Outstanding`: the unallocated
 * remainder after receipts and credit notes. Ageing is that remainder bucketed
 * by how long ago the transaction was dated.
 *
 * Per service: a debtor account is coded `{stand}-{TOKEN}-{portion}`, one
 * account per service, so the token IS the service — the same split the ledger
 * importer uses, so the two reports agree on what a service is.
 */
final class SageAgeingService
{
    private const SAGE = 'sage';

    /** Upper bound in days for each bucket; the last is open-ended. */
    private const BUCKETS = [
        'current' => 30,
        'days_30' => 60,
        'days_60' => 90,
        'days_90' => 120,
    ];

    /**
     * @return array{snapshot: AgeingSnapshot, currencies: list<string>, services: int}
     */
    public function capture(?CarbonImmutable $asAt = null): array
    {
        $municipality = Municipality::firstWhere('code', config('sage.municipality.code'))
            ?? Municipality::first();

        if ($municipality === null) {
            throw new RuntimeException('No municipality to attach the ageing snapshot to.');
        }

        $conn = DB::connection(self::SAGE);

        // Default to the latest transaction in the ledger rather than today: a
        // council that has not posted for months would otherwise show every
        // balance in the oldest bucket purely because time passed.
        $asAt ??= CarbonImmutable::parse(
            $conn->table('PostAR')->max('TxDate') ?? now()->toDateString()
        );

        $currencies = $conn->table('Currency')->pluck('CurrencyCode', 'CurrencyLink');
        $labels = $this->serviceLabels($municipality->id);

        // Account → its service token, resolved once. Small enough to hold, and
        // it saves joining Client on every one of tens of thousands of rows.
        $accountTokens = [];
        foreach ($conn->table('Client')->select('DCLink', 'Account')->cursor() as $client) {
            [, $token] = LedgerAccount::split((string) $client->Account);
            $accountTokens[(int) $client->DCLink] = $token;
        }

        $matrix = [];
        $accountsSeen = [];
        $transactions = 0;

        $rows = $conn->table('PostAR')
            ->select('AccountLink', 'TxDate', 'Outstanding', 'iCurrencyID')
            ->where('Outstanding', '<>', 0)
            ->cursor();

        foreach ($rows as $row) {
            $transactions++;
            $link = (int) $row->AccountLink;
            $token = $accountTokens[$link] ?? '(other)';
            $currency = (string) ($currencies[(int) $row->iCurrencyID] ?? 'UNK');
            $bucket = $this->bucketFor($asAt, (string) $row->TxDate);

            $key = $token.'|'.$currency;
            $matrix[$key]['token'] = $token;
            $matrix[$key]['currency'] = $currency;
            $matrix[$key][$bucket] = ($matrix[$key][$bucket] ?? 0.0) + (float) $row->Outstanding;
            $matrix[$key]['total'] = ($matrix[$key]['total'] ?? 0.0) + (float) $row->Outstanding;
            $matrix[$key]['accounts'][$link] = true;
            $accountsSeen[$link] = true;
        }

        return app(CurrentMunicipality::class)->runFor($municipality->id, function () use (
            $municipality, $asAt, $conn, $matrix, $accountsSeen, $transactions, $labels
        ): array {
            return DB::transaction(function () use (
                $municipality, $asAt, $conn, $matrix, $accountsSeen, $transactions, $labels
            ): array {
                $snapshot = AgeingSnapshot::create([
                    'municipality_id' => $municipality->id,
                    'as_at' => $asAt->toDateString(),
                    'source_database' => (string) $conn->getDatabaseName(),
                    'account_count' => count($accountsSeen),
                    'transaction_count' => $transactions,
                ]);

                // Biggest balances first, so the report opens on what matters.
                uasort($matrix, fn ($a, $b) => abs($b['total']) <=> abs($a['total']));

                foreach ($matrix as $cell) {
                    AgeingRow::create([
                        'ageing_snapshot_id' => $snapshot->id,
                        'service_token' => $cell['token'],
                        'service_label' => $labels[$cell['token']] ?? $cell['token'],
                        'currency' => $cell['currency'],
                        'current' => round($cell['current'] ?? 0, 2),
                        'days_30' => round($cell['days_30'] ?? 0, 2),
                        'days_60' => round($cell['days_60'] ?? 0, 2),
                        'days_90' => round($cell['days_90'] ?? 0, 2),
                        'days_120_plus' => round($cell['days_120_plus'] ?? 0, 2),
                        'total' => round($cell['total'], 2),
                        'account_count' => count($cell['accounts'] ?? []),
                    ]);
                }

                return [
                    'snapshot' => $snapshot,
                    'currencies' => $snapshot->currencies(),
                    'services' => count($matrix),
                ];
            });
        });
    }

    /** Which bucket column a transaction date falls into. */
    private function bucketFor(CarbonImmutable $asAt, string $txDate): string
    {
        $age = CarbonImmutable::parse($txDate)->startOfDay()->diffInDays($asAt->startOfDay(), false);

        foreach (self::BUCKETS as $column => $upperBound) {
            if ($age <= $upperBound) {
                return $column;
            }
        }

        return 'days_120_plus';
    }

    /**
     * Friendly names for the tokens, from the service types the ledger import
     * created (`LEDGER-REF` → "Refuse Collection").
     *
     * @return array<string, string>
     */
    private function serviceLabels(int $municipalityId): array
    {
        return app(CurrentMunicipality::class)->runFor($municipalityId, function (): array {
            $labels = [];
            foreach (ServiceType::query()->get(['code', 'name']) as $type) {
                if (str_starts_with((string) $type->code, 'LEDGER-')) {
                    $labels[substr((string) $type->code, 7)] = (string) $type->name;
                }
            }

            return $labels;
        });
    }
}
