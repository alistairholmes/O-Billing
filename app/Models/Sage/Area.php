<?php

declare(strict_types=1);

namespace App\Models\Sage;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sage `_mtblAreas` — the billing-level suburb/ward a property sits in.
 * `cArea` holds the code and `cAreaDescription` the human name.
 */
class Area extends SageModel
{
    protected $table = '_mtblAreas';

    protected $primaryKey = 'idAreas';

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'iPropertyAreaID', 'idAreas');
    }
}
