<?php

declare(strict_types=1);

namespace App\Models\Sage;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sage `_mtblPropertyPortionServices` — the link that says a property portion is
 * billed for a given service on a given rate tariff (and, for metered services,
 * meter). `bBillable` flags whether it currently raises charges.
 */
class PropertyService extends SageModel
{
    protected $table = '_mtblPropertyPortionServices';

    protected $primaryKey = 'idPropertyPortionServices';

    protected function casts(): array
    {
        return [
            'bBillable' => 'boolean',
        ];
    }

    public function portion(): BelongsTo
    {
        return $this->belongsTo(PropertyPortion::class, 'iPropertyPortionID', 'idPropertyPortions');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'iPortionServiceID', 'ID');
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class, 'iServiceRateTariffID', 'idRateTariffs');
    }
}
