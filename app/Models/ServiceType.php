<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMunicipality;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A billable municipal service, e.g. Property Rates, Refuse Removal, Sewerage.
 * (Water metering is deferred.) The billing_basis decides how a tariff's rate is
 * applied to a customer.
 */
class ServiceType extends Model
{
    use BelongsToMunicipality;
    use HasFactory;

    public const BASIS_FLAT = 'flat';
    public const BASIS_PER_PROPERTY_VALUE = 'per_property_value';
    public const BASIS_PER_UNIT = 'per_unit';

    protected $fillable = [
        'municipality_id', 'name', 'code', 'billing_basis',
        'unit_label', 'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * Guarantee the type has at least one billable variant. Used by programmatic
     * callers (seeders, the setup wizard) that create a type without explicit
     * variants; the Filament editor instead manages variants via a repeater.
     */
    public function ensureDefaultService(bool $taxable = true): Service
    {
        return $this->services()->firstOrCreate(
            ['is_default' => true],
            [
                'municipality_id' => $this->municipality_id,
                'name' => $this->name,
                'code' => $this->code,
                'taxable' => $taxable,
                'active' => true,
            ],
        );
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function tariffs(): HasManyThrough
    {
        return $this->hasManyThrough(Tariff::class, Service::class);
    }

    public static function billingBases(): array
    {
        return [
            self::BASIS_FLAT => 'Flat monthly charge',
            self::BASIS_PER_PROPERTY_VALUE => 'Rate on property value',
            self::BASIS_PER_UNIT => 'Per unit (quantity)',
        ];
    }
}
