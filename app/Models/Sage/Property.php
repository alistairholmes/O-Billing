<?php

declare(strict_types=1);

namespace App\Models\Sage;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sage `_mtblProperties` — a rateable stand/erf: its valuation
 * (LandValue / ImprovementValue / LandSize), the `Area` it sits in, and the
 * `portions` it is split into (each portion carries a billed consumer). `cERFNo`
 * is the erf/stand number.
 */
class Property extends SageModel
{
    protected $table = '_mtblProperties';

    protected $primaryKey = 'idProperty';

    protected function casts(): array
    {
        return [
            'fLandValue' => 'float',
            'fImprovementValue' => 'float',
            'fLandSize' => 'float',
            'iNoPortions' => 'integer',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'iPropertyAreaID', 'idAreas');
    }

    public function ratingCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'iRatingID', 'idCategory');
    }

    public function usageCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'iUsageID', 'idCategory');
    }

    public function portions(): HasMany
    {
        return $this->hasMany(PropertyPortion::class, 'iPortionPropertyID', 'idProperty');
    }

    /** Total rateable value = land + improvements. */
    public function marketValue(): float
    {
        return (float) $this->fLandValue + (float) $this->fImprovementValue;
    }

    /** The concatenated address lines, blanks removed. */
    public function addressLabel(): string
    {
        return collect([
            $this->cAddress1, $this->cAddress2, $this->cAddress3,
            $this->cAddress4, $this->cAddress5,
        ])->filter()->implode(', ');
    }
}
