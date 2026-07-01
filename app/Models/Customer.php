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
 * A rate-payer / account holder, located in a suburb (billing-level area). Each
 * customer is billed in their own currency for the services they subscribe to.
 */
class Customer extends Model
{
    use BelongsToMunicipality;
    use HasFactory;

    protected $fillable = [
        'municipality_id', 'area_id', 'account_number', 'name', 'type',
        'email', 'phone', 'address', 'property_value', 'land_size',
        'land_value', 'improvement_value', 'currency', 'active',
    ];

    protected function casts(): array
    {
        return [
            'property_value' => 'decimal:2',
            'land_size' => 'decimal:2',
            'land_value' => 'decimal:2',
            'improvement_value' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'customer_service');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
