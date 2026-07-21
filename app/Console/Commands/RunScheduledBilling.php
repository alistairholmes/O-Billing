<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillingSchedule;
use App\Services\Billing\BillingScheduleRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Fires every billing schedule that is now due. Meant to run on the Laravel
 * scheduler (hourly). Each schedule is processed independently so one failure
 * doesn't block the rest.
 */
class RunScheduledBilling extends Command
{
    protected $signature = 'billing:run-scheduled';

    protected $description = 'Create (and optionally post) billing runs for any schedules that are due';

    public function handle(BillingScheduleRunner $runner): int
    {
        $due = BillingSchedule::withoutGlobalScopes()
            ->where('active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', Carbon::now())
            ->get();

        if ($due->isEmpty()) {
            $this->info('No billing schedules are due.');

            return self::SUCCESS;
        }

        $fired = 0;
        foreach ($due as $schedule) {
            try {
                $run = $runner->fire($schedule);
                $fired++;
                $this->info("Schedule '{$schedule->name}' → run {$run->run_number} ({$run->invoice_count} invoices)"
                    .($schedule->auto_post ? ' — queued to Sage' : ''));
            } catch (Throwable $e) {
                $this->error("Schedule '{$schedule->name}' (#{$schedule->id}) failed: ".$e->getMessage());
                report($e);
            }
        }

        $this->info("Fired {$fired} of {$due->count()} due schedule(s).");

        return self::SUCCESS;
    }
}
