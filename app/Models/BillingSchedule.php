<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMunicipality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A recurring billing schedule. On its cadence it creates a BillingRun (with the
 * configured scope), generates its invoices, and — when auto_post is on — queues
 * the post to Sage. Mirrors Sage's Annuity Billing Configuration.
 */
class BillingSchedule extends Model
{
    use BelongsToMunicipality;

    protected $fillable = [
        'municipality_id', 'name', 'active', 'auto_post',
        'frequency', 'day_mode', 'day_of_month',
        'start_date', 'end_date', 'max_occurrences', 'occurrences_count',
        'next_run_at', 'last_run_at',
        'service_ids', 'account_from', 'account_to', 'area_from_id', 'area_to_id',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'auto_post' => 'boolean',
            'day_of_month' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'max_occurrences' => 'integer',
            'occurrences_count' => 'integer',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'service_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Recompute the first run when the schedule is (re)activated or its timing
        // changes. The runner sets next_run_at directly after each fire, so that
        // is left untouched here (timing fields not dirty).
        static::saving(function (BillingSchedule $schedule): void {
            $timingChanged = $schedule->isDirty([
                'frequency', 'day_mode', 'day_of_month', 'start_date', 'end_date', 'max_occurrences',
            ]);

            if ($schedule->active && ($schedule->next_run_at === null || $timingChanged)) {
                $schedule->next_run_at = $schedule->firstRunAt();
            }
        });
    }

    /** The first occurrence on or after start_date, or null if already finished. */
    public function firstRunAt(): ?Carbon
    {
        $start = $this->start_date->copy()->startOfDay();

        $candidate = $this->snap($start->copy());
        while ($candidate->lt($start)) {
            $candidate = $this->snap($this->step($candidate));
        }

        return $this->stopIfFinished($candidate);
    }

    /** The next occurrence strictly after the given due date, or null if finished. */
    public function computeNextRunAt(Carbon $after): ?Carbon
    {
        $candidate = $this->snap($this->step($after->copy()));

        return $this->stopIfFinished($candidate);
    }

    /** Advance one whole period. */
    private function step(Carbon $date): Carbon
    {
        return match ($this->frequency) {
            'weekly' => $date->addWeek(),
            'quarterly' => $date->addMonthsNoOverflow(3),
            'annually' => $date->addYearNoOverflow(),
            default => $date->addMonthNoOverflow(), // monthly
        };
    }

    /** Snap a date to the schedule's run day (month-based cadences only). */
    private function snap(Carbon $date): Carbon
    {
        $date = $date->startOfDay();

        if ($this->frequency === 'weekly') {
            return $date; // weekly fires every 7 days from start_date, no day rule
        }

        if ($this->day_mode === 'last') {
            return $date->endOfMonth()->startOfDay();
        }

        $day = min((int) ($this->day_of_month ?? 1), $date->daysInMonth);

        return $date->setDay($day);
    }

    /** Null out once past end_date or the occurrence limit is reached. */
    private function stopIfFinished(Carbon $candidate): ?Carbon
    {
        if ($this->end_date !== null && $candidate->gt($this->end_date->copy()->endOfDay())) {
            return null;
        }

        if ($this->max_occurrences !== null && $this->occurrences_count >= $this->max_occurrences) {
            return null;
        }

        return $candidate;
    }
}
