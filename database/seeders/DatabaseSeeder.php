<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\AreaType;
use App\Models\Customer;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\Tariff;
use App\Models\User;
use App\Support\DefaultSetup;
use App\Support\Tenancy\CurrentMunicipality;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@obilling.test'],
            ['name' => 'Olimem Admin', 'password' => Hash::make('password')],
        );

        // Zimbabwean demo municipality billing in USD (base) and ZWG.
        $municipality = Municipality::firstOrCreate(
            ['name' => 'Harare City Council'],
            [
                'code' => 'HRE',
                'base_currency' => 'USD',
                'supported_currencies' => ['USD', 'ZWG'],
                'tax_rate' => 0.15,
                'tax_label' => 'VAT',
                'contact_email' => 'billing@hararecity.gov.zw',
                'setup_completed_at' => now(),
            ],
        );

        $user->municipalities()->syncWithoutDetaching([$municipality->id]);

        // Everything below is created within the tenant scope.
        app(CurrentMunicipality::class)->runFor($municipality->id, function () use ($municipality): void {
            if (AreaType::count() === 0) {
                DefaultSetup::seed($municipality);
            }

            $this->seedAreasAndBilling($municipality);
            $this->seedBillingRuns($municipality);
        });
    }

    private function seedBillingRuns(Municipality $municipality): void
    {
        if (\App\Models\BillingRun::count() > 0) {
            return;
        }

        // Two completed monthly runs so the dashboard has data on first login.
        $seq = 0;
        foreach ([now()->startOfMonth()->subMonth(), now()->startOfMonth()] as $period) {
            $run = \App\Models\BillingRun::create([
                'municipality_id' => $municipality->id,
                'run_number' => sprintf('BR-%s-%04d', $period->format('Ym'), ++$seq),
                'period_month' => $period,
                'frequency' => 'monthly',
                'description' => $period->format('F Y').' monthly run',
            ]);

            app(\App\Services\Billing\BillingRunService::class)->generate($run);
        }
    }

    private function seedAreasAndBilling(Municipality $municipality): void
    {
        if (Area::count() > 0) {
            return; // already seeded
        }

        $provinceType = AreaType::where('level', 1)->first();
        $cityType = AreaType::where('level', 3)->first();
        $suburbType = AreaType::where('is_billing_level', true)->first();

        // Flat-rate services bill against their single default variant.
        $flatServices = Service::query()
            ->where('is_default', true)
            ->with('serviceType')
            ->get()
            ->keyBy(fn (Service $s) => $s->serviceType->code);

        // Property Rates splits into density bands; pick the variant per suburb.
        $ratesBand = Service::query()
            ->whereHas('serviceType', fn ($q) => $q->where('code', 'RATES'))
            ->get()
            ->keyBy('code'); // RATES-HD, RATES-LD

        // Which density band each suburb falls into (townships = high density).
        $density = [
            'Borrowdale' => 'low', 'Avondale' => 'low', 'Highlands' => 'low',
            'Hillside' => 'low', 'Murambi' => 'low',
            'Mbare' => 'high', 'Nkulumane' => 'high', 'Dangamvura' => 'high',
        ];

        // Tariff per service code, per currency (USD base, ZWG = Zimbabwe Gold).
        $rates = [
            'REFUSE' => ['USD' => 15, 'ZWG' => 200],
            'SEWER' => ['USD' => 10, 'ZWG' => 130],
            'ROADS' => ['USD' => 5, 'ZWG' => 65],
        ];

        // Property-rates multiplier on property value, per density band.
        $ratesByBand = [
            'high' => ['USD' => 0.0008, 'ZWG' => 0.0008],
            'low' => ['USD' => 0.0005, 'ZWG' => 0.0005],
        ];

        // Sage transaction (revenue) codes per service, keyed by the service code.
        $trCodes = [
            'RATES-HD' => '5001', 'RATES-LD' => '5002',
            'REFUSE' => '5100', 'SEWER' => '5200', 'ROADS' => '5300',
        ];

        // Zimbabwe metropolitan/provincial structure → city → suburb.
        $structure = [
            'Harare' => [
                'Harare' => ['Borrowdale', 'Avondale', 'Mbare', 'Highlands'],
            ],
            'Bulawayo' => [
                'Bulawayo' => ['Hillside', 'Nkulumane'],
            ],
            'Manicaland' => [
                'Mutare' => ['Murambi', 'Dangamvura'],
            ],
        ];

        $accountSeq = 1000;

        foreach ($structure as $provinceName => $cities) {
            $province = Area::create([
                'municipality_id' => $municipality->id,
                'area_type_id' => $provinceType->id,
                'name' => $provinceName,
            ]);

            foreach ($cities as $cityName => $suburbs) {
                $city = Area::create([
                    'municipality_id' => $municipality->id,
                    'area_type_id' => $cityType->id,
                    'parent_id' => $province->id,
                    'name' => $cityName,
                ]);

                foreach ($suburbs as $suburbName) {
                    $suburb = Area::create([
                        'municipality_id' => $municipality->id,
                        'area_type_id' => $suburbType->id,
                        'parent_id' => $city->id,
                        'name' => $suburbName,
                    ]);

                    // Borrowdale bills in ZWG too (multi-currency demo); others USD only.
                    $currencies = $suburbName === 'Borrowdale' ? ['USD', 'ZWG'] : ['USD'];

                    // This suburb's density band selects its Property Rates variant.
                    $band = $density[$suburbName] ?? 'high';
                    $ratesService = $ratesBand[$band === 'high' ? 'RATES-HD' : 'RATES-LD'];

                    // The services billed in this suburb: the band's rates variant
                    // plus each flat service's default variant.
                    $suburbServices = collect([$ratesService])->merge($flatServices->values());

                    foreach ($suburbServices as $service) {
                        $code = $service->serviceType->code;
                        foreach ($currencies as $currency) {
                            $rate = $code === 'RATES'
                                ? $ratesByBand[$band][$currency]
                                : $rates[$code][$currency];

                            Tariff::create([
                                'municipality_id' => $municipality->id,
                                'area_id' => $suburb->id,
                                'service_id' => $service->id,
                                'rate' => $rate,
                                'currency' => $currency,
                                'tr_code' => $trCodes[$service->code] ?? null,
                                'effective_from' => now()->startOfYear(),
                                'active' => true,
                            ]);
                        }
                    }

                    // A handful of customers per suburb.
                    $count = $suburbName === 'Borrowdale' ? 6 : 4;
                    for ($i = 0; $i < $count; $i++) {
                        $isBusiness = $i === 0;
                        // A couple of Borrowdale accounts bill in ZWG.
                        $currency = ($suburbName === 'Borrowdale' && $i >= 4) ? 'ZWG' : 'USD';

                        $propertyValue = $this->propertyValue($currency, $isBusiness);
                        // Split the rateable value into land + improvements for the
                        // valuation-roll fields (land ~40%, improvements ~60%).
                        $landValue = (int) round($propertyValue * 0.4);

                        $customer = Customer::create([
                            'municipality_id' => $municipality->id,
                            'area_id' => $suburb->id,
                            'account_number' => 'ACC-'.(++$accountSeq),
                            'name' => $isBusiness ? "{$suburbName} Traders (Pvt) Ltd" : "{$suburbName} Resident {$i}",
                            'type' => $isBusiness ? 'business' : 'residential',
                            'email' => strtolower(str_replace(' ', '', $suburbName))."{$i}@example.com",
                            'property_value' => $propertyValue,
                            'land_size' => $isBusiness ? random_int(800, 4000) : random_int(200, 1000),
                            'land_value' => $landValue,
                            'improvement_value' => $propertyValue - $landValue,
                            'currency' => $currency,
                            'active' => true,
                        ]);

                        // Subscribe everyone to this suburb's billed services — the
                        // density-matched Property Rates variant plus the flat ones.
                        // (Vacant-stand cases omitted for the demo.)
                        $customer->services()->sync($suburbServices->pluck('id')->all());
                    }
                }
            }
        }
    }

    /** Property value in the customer's own currency (USD values are far smaller than ZWG). */
    private function propertyValue(string $currency, bool $isBusiness): int
    {
        return match ($currency) {
            'ZWG' => $isBusiness ? random_int(5, 15) * 1_000_000 : random_int(800, 4000) * 1000,
            default => $isBusiness ? random_int(300, 900) * 1000 : random_int(40, 250) * 1000, // USD
        };
    }
}
