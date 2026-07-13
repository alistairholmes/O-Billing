<?php

declare(strict_types=1);

namespace App\Models\Sage;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sage `_ccg_EB_Services` — a billable service. `CalculationMethod`
 * (1 = fixed, 2 = metered/consumption, 3 = rated) drives how a charge is worked
 * out, and `MeasurableUnit` (e.g. KL) applies to metered services. In the
 * municipal-billing (`_mtbl`) schema, rate tariffs price each service.
 */
class Service extends SageModel
{
    protected $table = '_ccg_EB_Services';

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class, 'iRateTariffServiceID', 'ID');
    }
}
