<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service's ageing within a snapshot, in one currency. Not tenant-scoped
 * itself — it reaches the municipality through its snapshot.
 */
class AgeingRow extends Model
{
    /** Ageing buckets, in order. Column => column heading. */
    public const BUCKETS = [
        'current' => 'Current',
        'days_30' => '31–60 days',
        'days_60' => '61–90 days',
        'days_90' => '91–120 days',
        'days_120_plus' => '120+ days',
    ];

    public $timestamps = false;

    protected $fillable = [
        'ageing_snapshot_id', 'service_token', 'service_label', 'currency',
        'current', 'days_30', 'days_60', 'days_90', 'days_120_plus',
        'total', 'account_count',
    ];

    protected function casts(): array
    {
        return [
            'current' => 'decimal:2',
            'days_30' => 'decimal:2',
            'days_60' => 'decimal:2',
            'days_90' => 'decimal:2',
            'days_120_plus' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AgeingSnapshot::class, 'ageing_snapshot_id');
    }

    /** Share of this row that is 90 days or older — the collectability signal. */
    public function overdueShare(): float
    {
        $total = (float) $this->total;

        return $total == 0.0
            ? 0.0
            : ((float) $this->days_90 + (float) $this->days_120_plus) / $total;
    }
}
