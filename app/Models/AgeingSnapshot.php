<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMunicipality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One capture of the council's debtor ageing, as at a date. Sourced from Sage's
 * per-transaction `Outstanding` figures, because O-Billing raises invoices but
 * never sees the receipts that settle them.
 */
class AgeingSnapshot extends Model
{
    use BelongsToMunicipality;

    protected $fillable = [
        'municipality_id', 'as_at', 'source_database', 'account_count', 'transaction_count',
    ];

    protected function casts(): array
    {
        return ['as_at' => 'date'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AgeingRow::class);
    }

    /** Currencies present in this snapshot, largest total first. */
    public function currencies(): array
    {
        return $this->rows()
            ->selectRaw('currency, SUM(total) AS t')
            ->groupBy('currency')
            ->orderByDesc('t')
            ->pluck('currency')
            ->all();
    }

    /** @return array<string, array<string, float>> currency => bucket => amount */
    public function totalsByCurrency(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            foreach (AgeingRow::BUCKETS as $column => $label) {
                $out[$row->currency][$column] = ($out[$row->currency][$column] ?? 0) + (float) $row->{$column};
            }
            $out[$row->currency]['total'] = ($out[$row->currency]['total'] ?? 0) + (float) $row->total;
        }

        return $out;
    }
}
