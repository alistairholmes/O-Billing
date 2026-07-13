<?php

declare(strict_types=1);

namespace App\Models\Sage;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sage `_mtblPropertyPortions` — a billed portion of a property. Each portion
 * carries its own consumer (the ratepayer, via `iPortionConsumerID` →
 * Client.DCLink), its own valuation, and the set of services billed on it.
 */
class PropertyPortion extends SageModel
{
    protected $table = '_mtblPropertyPortions';

    protected $primaryKey = 'idPropertyPortions';

    protected function casts(): array
    {
        return [
            'fPortionSize' => 'float',
            'fPortionLandValue' => 'float',
            'fPortionImprovementValue' => 'float',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'iPortionPropertyID', 'idProperty');
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'iPortionConsumerID', 'DCLink');
    }

    public function services(): HasMany
    {
        return $this->hasMany(PropertyService::class, 'iPropertyPortionID', 'idPropertyPortions');
    }
}
