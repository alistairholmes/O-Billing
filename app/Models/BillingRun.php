<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMunicipality;
use App\Support\Currencies;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A billing run generates invoices for all active customers for a given month,
 * so the municipality can see how much it is billing. Totals are kept per
 * currency to support multi-currency municipalities.
 */
class BillingRun extends Model
{
    use BelongsToMunicipality;
    use HasFactory;

    protected $fillable = [
        'municipality_id', 'run_number', 'period_month', 'frequency',
        'description', 'status', 'service_ids', 'account_from', 'account_to',
        'area_from_id', 'area_to_id', 'invoice_count', 'currency_totals', 'run_at',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'service_ids' => 'array',
            'currency_totals' => 'array',
            'invoice_count' => 'integer',
            'run_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** Billing frequencies and how many months of the charge each raises. */
    public const FREQUENCY_MULTIPLIERS = [
        'weekly' => 12 / 52,
        'monthly' => 1.0,
        'quarterly' => 3.0,
        'annually' => 12.0,
    ];

    public static function frequencies(): array
    {
        return [
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'annually' => 'Annually',
        ];
    }

    public function frequencyMultiplier(): float
    {
        return self::FREQUENCY_MULTIPLIERS[$this->frequency] ?? 1.0;
    }

    /** Next sequential run number for the current tenant, e.g. BR-202606-0001. */
    public static function nextRunNumber(): string
    {
        return sprintf('BR-%s-%04d', now()->format('Ym'), static::count() + 1);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function areaFrom(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_from_id');
    }

    public function areaTo(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_to_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    /** Whether the run has been queued for, or already posted to, Sage. */
    public function isInSage(): bool
    {
        return in_array($this->posting_status, ['posting', 'posted'], true);
    }

    /** Per-currency totals rendered for display, e.g. "$5,786.10  •  ZWG 3,714.50". */
    public function formattedCurrencyTotals(): string
    {
        $totals = $this->currency_totals ?? [];

        if ($totals === []) {
            return '—';
        }

        return collect($totals)
            ->map(fn ($amount, $currency) => Currencies::format($amount, $currency))
            ->implode('  •  ');
    }

    /** A short human description of the run's scope for the billing reports. */
    public function scopeSummary(): string
    {
        $parts = [];

        $serviceCount = count($this->service_ids ?? []);
        $parts[] = $serviceCount === 0 ? 'All services' : $serviceCount.' service(s)';

        if ($this->account_from !== null || $this->account_to !== null) {
            $parts[] = 'Accounts '.($this->account_from ?? '…').'–'.($this->account_to ?? '…');
        }

        if ($this->area_from_id !== null || $this->area_to_id !== null) {
            $parts[] = 'Locations '.($this->areaFrom->name ?? '…').'–'.($this->areaTo->name ?? '…');
        }

        return implode(' · ', $parts);
    }
}
