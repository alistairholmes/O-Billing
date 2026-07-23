<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\BillingRuns\Pages\ViewBillingRun;
use App\Filament\Widgets\BillingIncomeByMonth;
use App\Filament\Widgets\PropertyMix;
use App\Filament\Widgets\RevenueByService;
use App\Filament\Widgets\RevenueBySuburb;
use App\Filament\Widgets\RevenueOverview;
use App\Models\Area;
use App\Models\AreaType;
use App\Models\BillingRun;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Municipality;
use App\Models\Sage\Client;
use App\Models\Service;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Sage\SageBillingRunPoster;
use App\Services\Sage\SagePropertyWriter;
use App\Support\Sage\LedgerAccount;
use App\Support\Tenancy\CurrentMunicipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

    public function test_import_page_renders(): void
    {
        // The live-Sage browser screens were dropped (see fd19f93); the import
        // page is the remaining Sage-facing page.
        [$muni, $user] = $this->tenantUser();
        $this->actingAs($user);

        $this->get("/admin/{$muni->id}/import-from-sage")->assertOk();
    }

    public function test_large_sage_tables_paginate_over_sql_server(): void
    {
        // SQL Server pagination needs an ORDER BY; the resources set defaultSort,
        // so a deep page must load without error and return a full page of rows.
        // Client is the large populated Sage table (the debtor ledger).
        $page2 = Client::query()->orderBy('Account')->paginate(25, ['*'], 'page', 2);

        $this->assertSame(2, $page2->currentPage());
        $this->assertCount(25, $page2->items());
        $this->assertGreaterThan(1000, $page2->total());
    }

    public function test_billing_run_invoices_display_in_the_panel(): void
    {
        [$muni, $user] = $this->tenantUser();
        app(CurrentMunicipality::class)->set($muni->id);

        $areaType = AreaType::create([
            'municipality_id' => $muni->id, 'name' => 'Area', 'level' => 1, 'is_billing_level' => true,
        ]);
        $area = Area::create([
            'municipality_id' => $muni->id, 'area_type_id' => $areaType->id, 'name' => 'CBD',
        ]);
        $customer = Customer::create([
            'municipality_id' => $muni->id, 'area_id' => $area->id, 'account_number' => 'ACC-1',
            'name' => 'Test Ratepayer', 'type' => 'residential', 'currency' => 'USD',
        ]);
        $run = BillingRun::create([
            'municipality_id' => $muni->id, 'run_number' => 'BR-TEST-1', 'period_month' => now()->startOfMonth(),
            'frequency' => 'monthly', 'status' => 'completed', 'invoice_count' => 1,
            'currency_totals' => ['USD' => 150.0], 'run_at' => now(),
        ]);
        $invoice = Invoice::create([
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
        app(CurrentMunicipality::class)->set($muni->id);
        $this->actingAs($user);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($muni);

        // Minimal billed data so the charts have something to aggregate.
        $areaType = AreaType::create(['municipality_id' => $muni->id, 'name' => 'Area', 'level' => 1, 'is_billing_level' => true]);
        $area = Area::create(['municipality_id' => $muni->id, 'area_type_id' => $areaType->id, 'name' => 'CBD']);
        $type = ServiceType::create(['municipality_id' => $muni->id, 'name' => 'Refuse', 'billing_basis' => 'flat']);
        $service = Service::create(['municipality_id' => $muni->id, 'service_type_id' => $type->id, 'name' => 'Refuse']);
        $customer = Customer::create(['municipality_id' => $muni->id, 'area_id' => $area->id, 'account_number' => 'A1', 'name' => 'Ratepayer', 'type' => 'residential', 'currency' => 'USD']);
        $run = BillingRun::create(['municipality_id' => $muni->id, 'run_number' => 'R1', 'period_month' => now()->startOfMonth(), 'frequency' => 'monthly', 'status' => 'completed']);
        $invoice = Invoice::create(['municipality_id' => $muni->id, 'billing_run_id' => $run->id, 'customer_id' => $customer->id, 'invoice_number' => 'I1', 'period_month' => now()->startOfMonth(), 'currency' => 'USD', 'subtotal' => 100, 'tax_total' => 15, 'total' => 115, 'status' => 'issued']);
        $invoice->lines()->create(['service_id' => $service->id, 'description' => 'Refuse', 'quantity' => 1, 'unit_amount' => 100, 'amount' => 100, 'tax_amount' => 15]);

        foreach ([
            BillingIncomeByMonth::class,
            RevenueByService::class,
            RevenueBySuburb::class,
            PropertyMix::class,
        ] as $widget) {
            Livewire::test($widget)->call('updateChartData')->assertOk();
        }

        Livewire::test(RevenueOverview::class)->assertOk();
    }

    public function test_post_to_sage_action_mounts_with_live_preview(): void
    {
        [$muni, $user] = $this->tenantUser();
        $this->actingAs($user);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($muni);

        $run = BillingRun::create([
            'municipality_id' => $muni->id, 'run_number' => 'BR-UI-TEST', 'period_month' => now()->startOfMonth(),
            'frequency' => 'monthly', 'status' => 'completed', 'invoice_count' => 0,
        ]);

        // Mounting the action builds the confirmation modal, which runs the live
        // dry-run preview against Sage; a resolution failure would throw here.
        Livewire::test(ViewBillingRun::class, ['record' => $run->getRouteKey()])
            ->mountAction('postToSage')
            ->assertOk();
    }

    /**
     * A live ledger account whose class both auto-resolves to a billable and
     * carries a debtors control account, so the poster can fully resolve it.
     * Keeps these tests portable across council databases (Binga, Gokwe, …).
     *
     * @return array{0: string, 1: string, 2: string} [stand, token, name]
     */
    private function liveBillableAccount(): array
    {
        $map = app(\App\Services\Sage\SagePriceImportService::class)->classItemMap();

        $rows = DB::connection('sage')->table('Client as c')
            ->join('CliClass as cc', 'cc.IdCliClass', '=', 'c.iClassID')
            ->whereIn('c.iClassID', array_keys($map))
            ->where('cc.iAccountsIDControlAcc', '>', 0)
            ->orderBy('c.Account')
            ->limit(50)
            ->get(['c.Account', 'c.Name']);

        foreach ($rows as $row) {
            [$stand, $token] = LedgerAccount::split((string) $row->Account);
            if ($stand !== '' && $token !== '(other)') {
                return [$stand, $token, trim((string) $row->Name) ?: 'Ratepayer'];
            }
        }

        $this->fail('No billable ledger client with a resolvable class found in the connected Sage database.');
    }

    public function test_billing_run_poster_preview_resolves_sage_accounts_without_writing(): void
    {
        [$muni] = $this->tenantUser();

        app(CurrentMunicipality::class)->runFor($muni->id, function () use ($muni): void {
            $areaType = AreaType::create([
                'municipality_id' => $muni->id, 'name' => 'Ward', 'level' => 1, 'is_billing_level' => true,
            ]);
            $ward = Area::create([
                'municipality_id' => $muni->id, 'area_type_id' => $areaType->id, 'name' => 'Njelele Plots',
            ]);
            // A real account from the connected Sage ledger (read-only lookup).
            [$stand, $token, $name] = $this->liveBillableAccount();

            $type = ServiceType::create([
                'municipality_id' => $muni->id, 'name' => 'Assessment Rates', 'code' => "LEDGER-{$token}",
                'billing_basis' => 'flat', 'default_frequency' => 'annually', 'active' => true,
            ]);
            $service = $type->ensureDefaultService();

            $customer = Customer::create([
                'municipality_id' => $muni->id, 'area_id' => $ward->id, 'account_number' => $stand,
                'name' => $name, 'type' => 'residential', 'currency' => 'USD', 'active' => true,
            ]);
            $run = BillingRun::create([
                'municipality_id' => $muni->id, 'run_number' => 'BR-POST-TEST', 'period_month' => now()->startOfMonth(),
                'frequency' => 'annually', 'status' => 'completed',
            ]);
            $invoice = Invoice::create([
                'municipality_id' => $muni->id, 'billing_run_id' => $run->id, 'customer_id' => $customer->id,
                'invoice_number' => '202607-99999', 'period_month' => now()->startOfMonth(), 'currency' => 'USD',
                'subtotal' => 90, 'tax_total' => 13.95, 'total' => 103.95, 'status' => 'issued', 'issued_at' => now(),
            ]);
            $invoice->lines()->create([
                'service_id' => $service->id, 'description' => 'Assessment Rates — Njelele Plots',
                'quantity' => 1, 'unit_amount' => 90, 'amount' => 90, 'tax_amount' => 13.95,
            ]);

            $result = app(SageBillingRunPoster::class)->preview($run);

            // The line resolved to a real Sage debtor, class control account and
            // service item, a balanced GL double entry was built in home currency,
            // USD amounts carried on the document — and nothing was written.
            $this->assertCount(1, $result['docs']);
            $this->assertSame([], $result['unresolved']);
            $doc = $result['docs'][0];
            $this->assertGreaterThan(0, $doc['post_ar']['AccountLink']);
            $this->assertSame(103.95, $doc['post_ar']['fForeignDebit']);
            $this->assertSame(103.95, $doc['header']['fInvTotInclForeign']);
            $this->assertSame(90.0, $doc['header']['fInvTotExclForeign']);
            $this->assertGreaterThan(1.0, $result['exchange_rate']);
            $debits = array_sum(array_column($doc['post_gl'], 'Debit'));
            $credits = array_sum(array_column($doc['post_gl'], 'Credit'));
            $this->assertEqualsWithDelta($debits, $credits, 0.001);
            $this->assertSame($doc['post_ar']['Debit'], $debits);
            $this->assertSame(0, $result['already_posted']);
            $this->assertStringStartsWith('INV', $result['next_invoice_number']);
        });
    }

    public function test_property_writer_creates_one_ledger_debtor_account_per_service(): void
    {
        [$muni] = $this->tenantUser();

        app(CurrentMunicipality::class)->runFor($muni->id, function () use ($muni): void {
            $writer = app(SagePropertyWriter::class);
            if ($writer->targetsPropertyModule()) {
                $this->markTestSkipped('The Sage write target runs the property module, not a debtors ledger.');
            }

            $wardId = (int) DB::connection('sage_write')->table('Areas')->min('idAreas');

            $areaType = AreaType::create([
                'municipality_id' => $muni->id, 'name' => 'Ward', 'level' => 1, 'is_billing_level' => true,
            ]);
            $ward = Area::create([
                'municipality_id' => $muni->id, 'area_type_id' => $areaType->id,
                'name' => 'Test Ward', 'sage_id' => "ward:{$wardId}",
            ]);
            $type = ServiceType::create([
                'municipality_id' => $muni->id, 'name' => 'Development Levy (Rural)', 'code' => 'LEDGER-DEVR',
                'billing_basis' => 'flat', 'active' => true,
            ]);
            $service = $type->ensureDefaultService();

            $stand = 'ZZT'.random_int(10000, 99999);
            $customer = Customer::create([
                'municipality_id' => $muni->id, 'area_id' => $ward->id, 'account_number' => $stand,
                'name' => 'Test Ratepayer', 'type' => 'residential', 'currency' => 'USD', 'active' => true,
            ]);
            $customer->services()->attach($service->id);
            $customer->load(['area', 'services.serviceType']);

            // All Sage writes happen inside this transaction and are rolled back.
            DB::connection('sage_write')->beginTransaction();
            try {
                $result = $writer->pushProperty($customer);

                $this->assertTrue($result['ok']);
                $this->assertSame('ledger', $result['mode']);
                $this->assertCount(1, $result['created']);
                $this->assertStringStartsWith("{$stand}-DEVR", $result['created'][0]);

                // The debtor exists with the token's class, the ward and USD, so
                // billing posting can resolve it like any imported account.
                $client = DB::connection('sage_write')->table('Client')
                    ->where('Account', $result['created'][0])->first();
                $this->assertNotNull($client);
                $this->assertNotNull($client->iClassID);
                $this->assertSame($wardId, (int) $client->iAreasID);
                $this->assertSame('Test Ratepayer', $client->Name);

                // A second push must refuse to duplicate the accounts.
                $again = $writer->pushProperty($customer);
                $this->assertFalse($again['ok']);
                $this->assertStringContainsString('Already in Sage', $again['error']);
            } finally {
                DB::connection('sage_write')->rollBack();
            }

            $this->assertSame(0, DB::connection('sage_write')
                ->table('Client')->where('Account', 'like', $stand.'%')->count());
        });
    }

    public function test_single_invoice_posts_to_sage_as_a_posted_document(): void
    {
        [$muni] = $this->tenantUser();

        app(CurrentMunicipality::class)->runFor($muni->id, function () use ($muni): void {
            $areaType = AreaType::create([
                'municipality_id' => $muni->id, 'name' => 'Ward', 'level' => 1, 'is_billing_level' => true,
            ]);
            $ward = Area::create([
                'municipality_id' => $muni->id, 'area_type_id' => $areaType->id, 'name' => 'Njelele Plots',
            ]);
            // A real account from the connected Sage ledger (read-only lookup).
            [$stand, $token, $name] = $this->liveBillableAccount();

            $type = ServiceType::create([
                'municipality_id' => $muni->id, 'name' => 'Assessment Rates', 'code' => "LEDGER-{$token}",
                'billing_basis' => 'flat', 'default_frequency' => 'annually', 'active' => true,
            ]);
            $service = $type->ensureDefaultService();

            $customer = Customer::create([
                'municipality_id' => $muni->id, 'area_id' => $ward->id, 'account_number' => $stand,
                'name' => $name, 'type' => 'residential', 'currency' => 'USD', 'active' => true,
            ]);
            $run = BillingRun::create([
                'municipality_id' => $muni->id, 'run_number' => 'BR-POST-ONE', 'period_month' => now()->startOfMonth(),
                'frequency' => 'annually', 'status' => 'completed',
            ]);
            $obNumber = 'OBTEST-'.random_int(100000, 999999);
            $invoice = Invoice::create([
                'municipality_id' => $muni->id, 'billing_run_id' => $run->id, 'customer_id' => $customer->id,
                'invoice_number' => $obNumber, 'period_month' => now()->startOfMonth(), 'currency' => 'USD',
                'subtotal' => 90, 'tax_total' => 13.95, 'total' => 103.95, 'status' => 'issued', 'issued_at' => now(),
            ]);
            $invoice->lines()->create([
                'service_id' => $service->id, 'description' => 'Assessment Rates — Njelele Plots',
                'quantity' => 1, 'unit_amount' => 90, 'amount' => 90, 'tax_amount' => 13.95,
            ]);

            // All Sage writes happen inside this transaction and are rolled back.
            DB::connection('sage_write')->beginTransaction();
            try {
                $poster = app(SageBillingRunPoster::class);
                $result = $poster->postInvoice($invoice);

                $this->assertArrayNotHasKey('error', $result);
                $this->assertSame(1, $result['posted']);

                // The posted document exists and carries the O-Billing number.
                $doc = DB::connection('sage_write')->table('InvNum')->where('ExtOrderNum', $obNumber)->first();
                $this->assertNotNull($doc);
                $this->assertSame($result['invoice_from'], $doc->InvNumber);
                $this->assertSame(4, (int) $doc->DocState);
                $this->assertEqualsWithDelta(103.95, (float) $doc->fInvTotInclForeign, 0.001);

                // Its GL double entry balances to the cent.
                $gl = DB::connection('sage_write')->table('PostGL')->where('Reference', $doc->InvNumber)->get();
                $this->assertEqualsWithDelta((float) $gl->sum('Debit'), (float) $gl->sum('Credit'), 0.005);

                // Posting the same invoice again is refused (double-bill guard).
                $again = $poster->postInvoice($invoice);
                $this->assertStringContainsString('already posted', $again['error'] ?? '');
            } finally {
                DB::connection('sage_write')->rollBack();
            }

            $this->assertSame(0, DB::connection('sage_write')->table('InvNum')->where('ExtOrderNum', $obNumber)->count());
        });
    }
}
