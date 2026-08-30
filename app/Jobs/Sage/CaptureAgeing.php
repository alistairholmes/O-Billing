<?php

declare(strict_types=1);

namespace App\Jobs\Sage;

use App\Models\SageOperation;
use App\Services\Sage\SageAgeingService;
use Carbon\CarbonImmutable;

/**
 * Captures a debtor ageing snapshot from Sage. Read-only against Sage — it
 * writes nothing to the council's books, only to O-Billing.
 */
final class CaptureAgeing extends SageJob
{
    protected function execute(SageOperation $operation): array
    {
        $asAt = ($date = $operation->params['as_at'] ?? null)
            ? CarbonImmutable::parse((string) $date)
            : null;

        $r = app(SageAgeingService::class)->capture($asAt);
        $snapshot = $r['snapshot'];

        return [
            [
                'snapshot_id' => $snapshot->id,
                'as_at' => $snapshot->as_at->toDateString(),
                'services' => $r['services'],
                'currencies' => $r['currencies'],
                'accounts' => $snapshot->account_count,
                'transactions' => $snapshot->transaction_count,
            ],
            sprintf(
                'Aged %s open transactions across %s accounts as at %s (%d service(s), %s).',
                number_format($snapshot->transaction_count),
                number_format($snapshot->account_count),
                $snapshot->as_at->format('j M Y'),
                $r['services'],
                implode(', ', $r['currencies']) ?: 'no currency',
            ),
        ];
    }

    protected function title(SageOperation $operation): string
    {
        return 'Debtor age analysis';
    }
}
