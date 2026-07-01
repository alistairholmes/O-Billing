<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AreaType;
use App\Models\Municipality;
use App\Models\ServiceType;

/**
 * Seeds a fresh municipality with a baseline hierarchy (Province → District →
 * City/Town → Suburb) and the common billable services, so a new tenant starts
 * with a working structure the Setup Wizard can refine. Water metering is
 * intentionally excluded for now.
 */
final class DefaultSetup
{
    public const AREA_TYPES = [
        ['name' => 'Province', 'level' => 1, 'is_billing_level' => false],
        ['name' => 'District', 'level' => 2, 'is_billing_level' => false],
        ['name' => 'City / Town', 'level' => 3, 'is_billing_level' => false],
        ['name' => 'Suburb', 'level' => 4, 'is_billing_level' => true],
    ];

    public const SERVICES = [
        // Property Rates is split into density bands (different rate per band);
        // the other services keep a single default variant.
        ['name' => 'Property Rates', 'code' => 'RATES', 'billing_basis' => ServiceType::BASIS_PER_PROPERTY_VALUE, 'taxable' => false, 'variants' => [
            ['name' => 'High Density', 'code' => 'RATES-HD'],
            ['name' => 'Low Density', 'code' => 'RATES-LD'],
        ]],
        ['name' => 'Refuse Removal', 'code' => 'REFUSE', 'billing_basis' => ServiceType::BASIS_FLAT, 'taxable' => true],
        ['name' => 'Sewerage', 'code' => 'SEWER', 'billing_basis' => ServiceType::BASIS_FLAT, 'taxable' => true],
        ['name' => 'Roads & Stormwater Levy', 'code' => 'ROADS', 'billing_basis' => ServiceType::BASIS_FLAT, 'taxable' => true],
    ];

    public static function seed(Municipality $municipality): void
    {
        foreach (self::AREA_TYPES as $type) {
            AreaType::create([...$type, 'municipality_id' => $municipality->id]);
        }

        foreach (self::SERVICES as $service) {
            $variants = $service['variants'] ?? [];
            // Taxability now lives on the service, not the group.
            $taxable = $service['taxable'] ?? true;
            unset($service['variants'], $service['taxable']);

            $type = ServiceType::create([...$service, 'municipality_id' => $municipality->id, 'active' => true]);

            if ($variants === []) {
                $type->ensureDefaultService($taxable);

                continue;
            }

            foreach ($variants as $variant) {
                $type->services()->create([
                    ...$variant,
                    'municipality_id' => $municipality->id,
                    'taxable' => $taxable,
                    'active' => true,
                ]);
            }
        }
    }
}
