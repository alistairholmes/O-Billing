<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToMunicipality;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A billable variant of a ServiceType, e.g. Property Rates → "High Density" /
 * "Low Density". Tariffs are priced per Service (per suburb, per currency) and
 * customers subscribe to specific Services. A ServiceType with no real variants
 * carries a single default Service so it behaves like a plain service.
 *
 * Billing basis (billing_basis, unit_label) lives on the parent ServiceType and
 * is inherited; taxability is set per service, since variants of the same group
 * can differ. Variants otherwise differ only in their rate.
 */
class Service extends Model
{
    use BelongsToMunicipality;
    use HasFactory;

    protected $fillable = [
        'municipality_id', 'service_type_id', 'name', 'code',
        'is_default', 'taxable', 'active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'taxable' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_service');
    }

    /**
     * Active services as Filament grouped select options, keyed by their parent
     * service type: ['Property Rates' => [3 => 'High Density', 4 => 'Low Density']].
     *
     * @return array<string, array<int, string>>
     */
    public static function groupedOptions(): array
    {
        return static::query()
            ->where('active', true)
            ->with('serviceType')
            ->get()
            ->groupBy(fn (self $s) => $s->serviceType?->name ?? 'Other')
            ->map(fn ($group) => $group->mapWithKeys(fn (self $s) => [$s->id => $s->name])->all())
            ->all();
    }

    /**
     * Human label: "Property Rates (High Density)", or just the type name for a
     * default variant.
     */
    public function displayName(): string
    {
        $type = $this->serviceType?->name ?? $this->name;

        if ($this->is_default || $this->name === $type) {
            return $type;
        }

        return "{$type} ({$this->name})";
    }
}
