<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AreaType;
use App\Models\BillingRun;
use App\Models\Customer;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\ServiceType;
use App\Models\Tariff;
use App\Services\Billing\BillingRunService;
use App\Support\Tenancy\CurrentMunicipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_bills_customers_using_suburb_tariffs_and_taxes_only_taxable_services(): void
    {
        $municipality = Municipality::create([
            'name' => 'Test Muni',
            'code' => 'TST',
            'base_currency' => 'ZAR',
            'supported_currencies' => ['ZAR'],
            'tax_rate' => 0.15,
            'tax_label' => 'VAT',
        ]);

        app(CurrentMunicipality::class)->runFor($municipality->id, function () use ($municipality): void {
            $suburbType = AreaType::create([
                'municipality_id' => $municipality->id,
                'name' => 'Suburb', 'level' => 1, 'is_billing_level' => true,
            ]);

            $suburb = Area::create([
                'municipality_id' => $municipality->id,
                'area_type_id' => $suburbType->id, 'name' => 'Testville',
            ]);

            $rates = ServiceType::create([
                'municipality_id' => $municipality->id, 'name' => 'Property Rates', 'code' => 'RATES',
                'billing_basis' => ServiceType::BASIS_PER_PROPERTY_VALUE, 'active' => true,
            ]);
            $refuse = ServiceType::create([
                'municipality_id' => $municipality->id, 'name' => 'Refuse', 'code' => 'REFUSE',
                'billing_basis' => ServiceType::BASIS_FLAT, 'active' => true,
            ]);

            // Taxability is set per service: rates exempt, refuse taxable.
            $ratesService = $rates->ensureDefaultService(taxable: false);
            $refuseService = $refuse->ensureDefaultService(taxable: true);

            Tariff::create(['municipality_id' => $municipality->id, 'area_id' => $suburb->id, 'service_id' => $ratesService->id, 'rate' => 0.0006, 'currency' => 'ZAR', 'active' => true]);
            Tariff::create(['municipality_id' => $municipality->id, 'area_id' => $suburb->id, 'service_id' => $refuseService->id, 'rate' => 250, 'currency' => 'ZAR', 'active' => true]);

            $customer = Customer::create([
                'municipality_id' => $municipality->id, 'area_id' => $suburb->id,
                'account_number' => 'A1', 'name' => 'Resident', 'type' => 'residential',
                'property_value' => 1_000_000, 'currency' => 'ZAR', 'active' => true,
            ]);
            $customer->services()->sync([$ratesService->id, $refuseService->id]);

            $run = BillingRun::create(['municipality_id' => $municipality->id, 'period_month' => now()->startOfMonth()]);
            $result = app(BillingRunService::class)->generate($run);

            $this->assertSame(1, $result['invoice_count']);

            $invoice = $run->invoices()->with('lines')->first();
            // Rates: 1,000,000 * 0.0006 = 600 (no tax). Refuse: 250 flat + 15% tax = 37.50.
            $this->assertCount(2, $invoice->lines);
            $this->assertSame(850.0, (float) $invoice->subtotal);
            $this->assertSame(37.5, (float) $invoice->tax_total);
            $this->assertSame(887.5, (float) $invoice->total);
            $this->assertSame(887.5, $result['currency_totals']['ZAR']);
        });
    }

    public function test_density_variants_of_a_service_type_bill_at_their_own_rate(): void
    {
        $municipality = Municipality::create([
            'name' => 'Variant Muni',
            'code' => 'VAR',
            'base_currency' => 'ZAR',
            'supported_currencies' => ['ZAR'],
            'tax_rate' => 0.0,
            'tax_label' => 'VAT',
        ]);

        app(CurrentMunicipality::class)->runFor($municipality->id, function () use ($municipality): void {
            $suburbType = AreaType::create([
                'municipality_id' => $municipality->id,
                'name' => 'Suburb', 'level' => 1, 'is_billing_level' => true,
            ]);
            $suburb = Area::create([
                'municipality_id' => $municipality->id,
                'area_type_id' => $suburbType->id, 'name' => 'Mixville',
            ]);

            // One service type, two density variants priced differently.
            $rates = ServiceType::create([
                'municipality_id' => $municipality->id, 'name' => 'Property Rates', 'code' => 'RATES',
                'billing_basis' => ServiceType::BASIS_PER_PROPERTY_VALUE, 'active' => true,
            ]);
            $high = Service::create(['municipality_id' => $municipality->id, 'service_type_id' => $rates->id, 'name' => 'High Density', 'code' => 'RATES-HD', 'taxable' => false, 'active' => true]);
            $low = Service::create(['municipality_id' => $municipality->id, 'service_type_id' => $rates->id, 'name' => 'Low Density', 'code' => 'RATES-LD', 'taxable' => false, 'active' => true]);

            Tariff::create(['municipality_id' => $municipality->id, 'area_id' => $suburb->id, 'service_id' => $high->id, 'rate' => 0.0008, 'currency' => 'ZAR', 'active' => true]);
            Tariff::create(['municipality_id' => $municipality->id, 'area_id' => $suburb->id, 'service_id' => $low->id, 'rate' => 0.0005, 'currency' => 'ZAR', 'active' => true]);

            // Two customers, same suburb and property value, different density band.
            $hdCustomer = Customer::create([
                'municipality_id' => $municipality->id, 'area_id' => $suburb->id,
                'account_number' => 'HD1', 'name' => 'Township Resident', 'type' => 'residential',
                'property_value' => 1_000_000, 'currency' => 'ZAR', 'active' => true,
            ]);
            $hdCustomer->services()->sync([$high->id]);

            $ldCustomer = Customer::create([
                'municipality_id' => $municipality->id, 'area_id' => $suburb->id,
                'account_number' => 'LD1', 'name' => 'Suburb Resident', 'type' => 'residential',
                'property_value' => 1_000_000, 'currency' => 'ZAR', 'active' => true,
            ]);
            $ldCustomer->services()->sync([$low->id]);

            $run = BillingRun::create(['municipality_id' => $municipality->id, 'period_month' => now()->startOfMonth()]);
            app(BillingRunService::class)->generate($run);

            // High density: 1,000,000 * 0.0008 = 800. Low density: * 0.0005 = 500.
            $this->assertSame(800.0, (float) $hdCustomer->invoices()->first()->total);
            $this->assertSame(500.0, (float) $ldCustomer->invoices()->first()->total);

            // The variant shows in the line description.
            $line = $hdCustomer->invoices()->first()->lines()->first();
            $this->assertSame('Property Rates (High Density) — Mixville', $line->description);
        });
    }

    public function test_run_frequency_scales_the_charge_and_scope_limits_who_is_billed(): void
    {
        $municipality = Municipality::create([
            'name' => 'Scope Muni', 'code' => 'SCP', 'base_currency' => 'ZAR',
            'supported_currencies' => ['ZAR'], 'tax_rate' => 0.0, 'tax_label' => 'VAT',
        ]);

        app(CurrentMunicipality::class)->runFor($municipality->id, function () use ($municipality): void {
            $suburbType = AreaType::create([
                'municipality_id' => $municipality->id,
                'name' => 'Suburb', 'level' => 1, 'is_billing_level' => true,
            ]);
            $suburb = Area::create([
                'municipality_id' => $municipality->id,
                'area_type_id' => $suburbType->id, 'name' => 'Testville',
            ]);

            $refuse = ServiceType::create([
                'municipality_id' => $municipality->id, 'name' => 'Refuse', 'code' => 'REFUSE',
                'billing_basis' => ServiceType::BASIS_FLAT, 'active' => true,
            ]);
            $refuseService = $refuse->ensureDefaultService(taxable: false);
            Tariff::create(['municipality_id' => $municipality->id, 'area_id' => $suburb->id, 'service_id' => $refuseService->id, 'rate' => 100, 'currency' => 'ZAR', 'active' => true]);

            foreach (['A1', 'A2', 'A3'] as $acc) {
                $c = Customer::create([
                    'municipality_id' => $municipality->id, 'area_id' => $suburb->id,
                    'account_number' => $acc, 'name' => "Resident {$acc}", 'type' => 'residential',
                    'currency' => 'ZAR', 'active' => true,
                ]);
                $c->services()->sync([$refuseService->id]);
            }

            // Quarterly run limited to accounts A1..A2.
            $run = BillingRun::create([
                'municipality_id' => $municipality->id,
                'run_number' => 'BR-TEST-0001',
                'period_month' => now()->startOfMonth(),
                'frequency' => 'quarterly',
                'account_from' => 'A1',
                'account_to' => 'A2',
            ]);
            $result = app(BillingRunService::class)->generate($run);

            // Only A1 and A2 billed (A3 out of range), each 100 x 3 months = 300.
            $this->assertSame(2, $result['invoice_count']);
            $this->assertSame(600.0, $result['currency_totals']['ZAR']);
            $this->assertSame(300.0, (float) $run->invoices()->first()->total);
        });
    }

    public function test_two_runs_in_the_same_period_get_unique_invoice_numbers(): void
    {
        $municipality = Municipality::create([
            'name' => 'Dup Muni', 'code' => 'DUP', 'base_currency' => 'ZAR',
            'supported_currencies' => ['ZAR'], 'tax_rate' => 0.0, 'tax_label' => 'VAT',
        ]);

        app(CurrentMunicipality::class)->runFor($municipality->id, function () use ($municipality): void {
            $suburbType = AreaType::create([
                'municipality_id' => $municipality->id,
                'name' => 'Suburb', 'level' => 1, 'is_billing_level' => true,
            ]);
            $suburb = Area::create([
                'municipality_id' => $municipality->id,
                'area_type_id' => $suburbType->id, 'name' => 'Testville',
            ]);
            $refuse = ServiceType::create([
                'municipality_id' => $municipality->id, 'name' => 'Refuse', 'code' => 'REFUSE',
                'billing_basis' => ServiceType::BASIS_FLAT, 'active' => true,
            ]);
            $service = $refuse->ensureDefaultService(taxable: false);
            Tariff::create(['municipality_id' => $municipality->id, 'area_id' => $suburb->id, 'service_id' => $service->id, 'rate' => 50, 'currency' => 'ZAR', 'active' => true]);

            foreach (['A1', 'A2'] as $acc) {
                $c = Customer::create([
                    'municipality_id' => $municipality->id, 'area_id' => $suburb->id,
                    'account_number' => $acc, 'name' => "Resident {$acc}", 'type' => 'residential',
                    'currency' => 'ZAR', 'active' => true,
                ]);
                $c->services()->sync([$service->id]);
            }

            $period = now()->startOfMonth();
            $billing = app(BillingRunService::class);

            // Two separate runs for the SAME period (e.g. an original and a correction).
            foreach (['BR-A', 'BR-B'] as $number) {
                $run = BillingRun::create([
                    'municipality_id' => $municipality->id, 'run_number' => $number,
                    'period_month' => $period, 'frequency' => 'monthly',
                ]);
                $billing->generate($run);
            }

            // 4 invoices, all with distinct invoice numbers (no unique-constraint clash).
            $numbers = \App\Models\Invoice::pluck('invoice_number');
            $this->assertCount(4, $numbers);
            $this->assertSame(4, $numbers->unique()->count());
        });
    }

    public function test_preview_dry_runs_without_persisting_and_matches_generate(): void
    {
        $municipality = Municipality::create([
            'name' => 'Preview Muni', 'code' => 'PRV', 'base_currency' => 'ZAR',
            'supported_currencies' => ['ZAR'], 'tax_rate' => 0.0, 'tax_label' => 'VAT',
        ]);

        app(CurrentMunicipality::class)->runFor($municipality->id, function () use ($municipality): void {
            $suburbType = AreaType::create([
                'municipality_id' => $municipality->id,
                'name' => 'Suburb', 'level' => 1, 'is_billing_level' => true,
            ]);
            $suburb = Area::create([
                'municipality_id' => $municipality->id,
                'area_type_id' => $suburbType->id, 'name' => 'Testville',
            ]);
            $refuse = ServiceType::create([
                'municipality_id' => $municipality->id, 'name' => 'Refuse', 'code' => 'REFUSE',
                'billing_basis' => ServiceType::BASIS_FLAT, 'active' => true,
            ]);
            $service = $refuse->ensureDefaultService(taxable: false);
            Tariff::create(['municipality_id' => $municipality->id, 'area_id' => $suburb->id, 'service_id' => $service->id, 'rate' => 100, 'currency' => 'ZAR', 'active' => true]);

            $customer = Customer::create([
                'municipality_id' => $municipality->id, 'area_id' => $suburb->id,
                'account_number' => 'A1', 'name' => 'Resident', 'type' => 'residential',
                'currency' => 'ZAR', 'active' => true,
            ]);
            $customer->services()->sync([$service->id]);

            $run = BillingRun::create([
                'municipality_id' => $municipality->id, 'run_number' => 'BR-PRV-0001',
                'period_month' => now()->startOfMonth(), 'frequency' => 'monthly',
            ]);

            $service2 = app(BillingRunService::class);
            $preview = $service2->preview($run);

            // Preview computes results but writes nothing and leaves the run draft.
            $this->assertSame(1, $preview['invoice_count']);
            $this->assertSame(100.0, $preview['currency_totals']['ZAR']);
            $this->assertSame(0, \App\Models\Invoice::count());
            $this->assertSame('draft', $run->fresh()->status);

            // Processing produces the same totals and now persists.
            $result = $service2->generate($run);
            $this->assertSame($preview['invoice_count'], $result['invoice_count']);
            $this->assertSame($preview['currency_totals'], $result['currency_totals']);
            $this->assertSame(1, \App\Models\Invoice::count());
            $this->assertSame('completed', $run->fresh()->status);
        });
    }
}
