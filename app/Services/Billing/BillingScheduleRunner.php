<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Jobs\Sage\PostBillingRun;
use App\Models\BillingRun;
use App\Models\BillingSchedule;
use App\Support\Sage\SageBridge;
use App\Support\Tenancy\CurrentMunicipality;
use Illuminate\Support\Carbon;

/**
 * Fires a billing schedule once: creates a billing run with the schedule's scope,
 * generates its invoices, optionally queues the Sage post, and advances the
 * schedule's counters/next-run. Shared by the scheduler command and the UI
 * "Run now" action so both behave identically.
 */
final class BillingScheduleRunner
{
    public function __construct(private readonly BillingRunService $runs) {}

    public function fire(BillingSchedule $schedule): BillingRun
    {
        return app(CurrentMunicipality::class)->runFor(
            (int) $schedule->municipality_id,
            function () use ($schedule): BillingRun {
                $due = $schedule->next_run_at?->copy() ?? Carbon::now();

                $run = BillingRun::create([
                    'municipality_id' => $schedule->municipality_id,
                    'run_number' => BillingRun::nextRunNumber(),
                    'period_month' => Carbon::now()->startOfMonth(),
                    'frequency' => $schedule->frequency,
                    'description' => "Scheduled: {$schedule->name}",
                    'status' => 'draft',
                    'service_ids' => $schedule->service_ids ?? [],
                    'account_from' => $schedule->account_from,
                    'account_to' => $schedule->account_to,
                    'area_from_id' => $schedule->area_from_id,
                    'area_to_id' => $schedule->area_to_id,
                ]);

                $this->runs->generate($run);
                $run->refresh();

                // Auto-post: queue the run to the on-site Sage worker (no live
                // Sage access needed here — the bridge does that).
                if ($schedule->auto_post && $run->invoice_count > 0) {
                    $run->forceFill(['posting_status' => 'posting'])->save();
                    SageBridge::queue('post_run', PostBillingRun::class, $run, ['mode' => 'post']);
                }

                // Advance the schedule (increment first so the occurrence limit is
                // evaluated against the count *including* this run).
                $schedule->forceFill([
                    'occurrences_count' => $schedule->occurrences_count + 1,
                    'last_run_at' => Carbon::now(),
                ]);
                $schedule->next_run_at = $schedule->computeNextRunAt($due);
                if ($schedule->next_run_at === null) {
                    $schedule->active = false; // finished (end date / occurrence limit)
                }
                $schedule->save();

                return $run;
            },
        );
    }
}
