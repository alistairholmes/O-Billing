<?php

declare(strict_types=1);

namespace App\Models\Sage;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sage `_mtblRateTariffs` — a named rate schedule for a service, usually per
 * property category (e.g. "Water Cons RES", "Refuse H/D"). The actual money is
 * in its `bands` (a single band for flat charges, several for block tariffs).
 * `cRateTariff` is the code and `cRateTariffDescription` the human name.
 */
class Tariff extends SageModel
{
    protected $table = '_mtblRateTariffs';

    protected $primaryKey = 'idRateTariffs';

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'iRateTariffServiceID', 'ID');
    }

    public function bands(): HasMany
    {
        return $this->hasMany(TariffBand::class, 'iRateTariffID', 'idRateTariffs');
    }

    public function billingTrCode(): BelongsTo
    {
        return $this->belongsTo(TrCode::class, 'iBillTrCodeID', 'idTrCodes');
    }
}
