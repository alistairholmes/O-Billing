<?php

declare(strict_types=1);

namespace App\Models\Sage;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sage `_mtblRateTariffBands` — one consumption/period band of a rate tariff,
 * holding the `fBandAmount` (the rate), the block ceiling `fToValue` and the
 * effective-from period. Flat charges have a single band; block water tariffs
 * have several rising bands.
 */
class TariffBand extends SageModel
{
    protected $table = '_mtblRateTariffBands';

    protected $primaryKey = 'idRateTariffBands';

    protected function casts(): array
    {
        return [
            'fBandAmount' => 'float',
            'fToValue' => 'float',
        ];
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class, 'iRateTariffID', 'idRateTariffs');
    }

    public function fromPeriod(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'iFromPeriodID', 'idPeriod');
    }
}
