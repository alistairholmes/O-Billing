<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke-tests that every "Sage (Live)" browser page and the import page render
 * for an authenticated tenant user. These pages read the live Sage SQL Server
 * database directly, so this also exercises the `sage` connection end to end.
 */
class SageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Municipality, 1: User} */
    private function tenantUser(): array
    {
        $muni = Municipality::create([
            'name' => 'Plumtree Town Council',
            'code' => 'PTC',
            'base_currency' => 'USD',
            'supported_currencies' => ['USD'],
            'tax_rate' => 0.15,
            'tax_label' => 'VAT',
            'active' => true,
            'setup_completed_at' => now(),
        ]);

        $user = User::factory()->create();
        $muni->users()->attach($user->id);

        return [$muni, $user];
    }

    public function test_sage_live_pages_and_import_page_render(): void
    {
        [$muni, $user] = $this->tenantUser();
        $this->actingAs($user);

        $pages = [
            "/admin/{$muni->id}/sage/properties",
            "/admin/{$muni->id}/sage/clients",
            "/admin/{$muni->id}/sage/areas",
            "/admin/{$muni->id}/sage/service-groups",
            "/admin/{$muni->id}/sage/tariffs",
            "/admin/{$muni->id}/sage/billing-runs",
            "/admin/{$muni->id}/import-from-sage",
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_large_sage_tables_paginate_over_sql_server(): void
    {
        // SQL Server pagination needs an ORDER BY; the resources set defaultSort,
        // so a deep page must load without error and return a full page of rows.
        // Client is the large populated Sage table (the debtor ledger).
        $page2 = \App\Models\Sage\Client::query()->orderBy('Account')->paginate(25, ['*'], 'page', 2);

        $this->assertSame(2, $page2->currentPage());
        $this->assertCount(25, $page2->items());
        $this->assertGreaterThan(1000, $page2->total());
    }

    public function test_billing_run_invoices_display_in_the_panel(): void
    {
        [$muni, $user] = $this->tenantUser();
        app(\App\Support\Tenancy\CurrentMunicipality::class)->set($muni->id);

        $areaType = \App\Models\AreaType::create([
            'municipality_id' => $muni->id, 'name' => 'Area', 'level' => 1, 'is_billing_level' => true,
        ]);
        $area = \App\Models\Area::create([
            'municipality_id' => $muni->id, 'area_type_id' => $areaType->id, 'name' => 'CBD',
        ]);
        $customer = \App\Models\Customer::create([
            'municipality_id' => $muni->id, 'area_id' => $area->id, 'account_number' => 'ACC-1',
            'name' => 'Test Ratepayer', 'type' => 'residential', 'currency' => 'USD',
        ]);
        $run = \App\Models\BillingRun::create([
            'municipality_id' => $muni->id, 'run_number' => 'BR-TEST-1', 'period_month' => now()->startOfMonth(),
            'frequency' => 'monthly', 'status' => 'completed', 'invoice_count' => 1,
            'currency_totals' => ['USD' => 150.0], 'run_at' => now(),
        ]);
        $invoice = \App\Models\Invoice::create([
            'municipality_id' => $muni->id, 'billing_run_id' => $run->id, 'customer_id' => $customer->id,
            'invoice_number' => 'INV-TEST-1', 'period_month' => now()->startOfMonth(), 'currency' => 'USD',
            'subtotal' => 130, 'tax_total' => 20, 'total' => 150, 'status' => 'issued', 'issued_at' => now(),
        ]);
        $invoice->lines()->create([
            'tr_code' => 'REFUSE', 'description' => 'Refuse removal', 'quantity' => 1,
            'unit_amount' => 130, 'amount' => 130, 'tax_amount' => 20,
        ]);

        $this->actingAs($user);
        $base = "/admin/{$muni->id}";

        $this->get("{$base}/invoices")->assertOk()->assertSee('INV-TEST-1');
        $this->get("{$base}/invoices/{$invoice->id}")->assertOk()->assertSee('INV-TEST-1');
        $this->get("{$base}/billing-runs/{$run->id}")->assertOk();
    }

    public function test_dashboard_chart_widgets_generate_data(): void
    {
        [$muni, $user] = $this->tenantUser();
        app(\App\Support\Tenancy\CurrentMunicipality::class)->set($muni->id);
        $this->actingAs($user);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($muni);

        // Minimal billed data so the charts have something to aggregate.
        $areaType = \App\Models\AreaType::create(['municipality_id' => $muni->id, 'name' => 'Area', 'level' => 1, 'is_billing_level' => true]);
        $area = \App\Models\Area::create(['municipality_id' => $muni->id, 'area_type_id' => $areaType->id, 'name' => 'CBD']);
        $type = \App\Models\ServiceType::create(['municipality_id' => $muni->id, 'name' => 'Refuse', 'billing_basis' => 'flat']);
        $service = \App\Models\Service::create(['municipality_id' => $muni->id, 'service_type_id' => $type->id, 'name' => 'Refuse']);
        $customer = \App\Models\Customer::create(['municipality_id' => $muni->id, 'area_id' => $area->id, 'account_number' => 'A1', 'name' => 'Ratepayer', 'type' => 'residential', 'currency' => 'USD']);
        $run = \App\Models\BillingRun::create(['municipality_id' => $muni->id, 'run_number' => 'R1', 'period_month' => now()->startOfMonth(), 'frequency' => 'monthly', 'status' => 'completed']);
        $invoice = \App\Models\Invoice::create(['municipality_id' => $muni->id, 'billing_run_id' => $run->id, 'customer_id' => $customer->id, 'invoice_number' => 'I1', 'period_month' => now()->startOfMonth(), 'currency' => 'USD', 'subtotal' => 100, 'tax_total' => 15, 'total' => 115, 'status' => 'issued']);
        $invoice->lines()->create(['service_id' => $service->id, 'description' => 'Refuse', 'quantity' => 1, 'unit_amount' => 100, 'amount' => 100, 'tax_amount' => 15]);

        foreach ([
            \App\Filament\Widgets\BillingIncomeByMonth::class,
            \App\Filament\Widgets\RevenueByService::class,
            \App\Filament\Widgets\RevenueBySuburb::class,
            \App\Filament\Widgets\PropertyMix::class,
        ] as $widget) {
            \Livewire\Livewire::test($widget)->call('updateChartData')->assertOk();
        }

        \Livewire\Livewire::test(\App\Filament\Widgets\RevenueOverview::class)->assertOk();
    }

    public function test_view_modals_render_their_infolists(): void
    {
        [$muni, $user] = $this->tenantUser();
        $this->actingAs($user);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($muni);

        // Mounting the view action builds and fills its infolist schema; if any
        // entry referenced a broken relation it would throw here. assertOk()
        // confirms the component (with the modal mounted) rendered cleanly. Client
        // and Service are the populated Sage tables in this deployment.
        $client = \App\Models\Sage\Client::query()->first();
        \Livewire\Livewire::test(\App\Filament\Resources\Sage\Pages\ListClients::class)
            ->mountTableAction('view', $client->getKey())
            ->assertOk();

        $service = \App\Models\Sage\Service::query()->first();
        \Livewire\Livewire::test(\App\Filament\Resources\Sage\Pages\ListServiceGroups::class)
            ->mountTableAction('view', $service->getKey())
            ->assertOk();
    }
}
