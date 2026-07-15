<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AreaType;
use App\Models\BillingRun;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The print/download PDF routes: they must serve PDFs (inline by default,
 * attachment with ?download=1) and deny users from other municipalities.
 */
class DocumentPdfTest extends TestCase
{
    use RefreshDatabase;

    private Municipality $municipality;

    private User $member;

    private User $outsider;

    private BillingRun $run;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->municipality = Municipality::create([
            'name' => 'Test Muni',
            'code' => 'TST',
            'base_currency' => 'USD',
            'supported_currencies' => ['USD'],
            'tax_rate' => 0.15,
            'tax_label' => 'VAT',
        ]);

        $this->member = User::factory()->create();
        $this->member->municipalities()->attach($this->municipality);

        $this->outsider = User::factory()->create();

        $suburbType = AreaType::create([
            'municipality_id' => $this->municipality->id,
            'name' => 'Suburb', 'level' => 1, 'is_billing_level' => true,
        ]);

        $suburb = Area::create([
            'municipality_id' => $this->municipality->id,
            'area_type_id' => $suburbType->id, 'name' => 'Testville',
        ]);

        $customer = Customer::create([
            'municipality_id' => $this->municipality->id,
            'area_id' => $suburb->id,
            'account_number' => 'ACC-001',
            'name' => 'Jane Ratepayer',
            'currency' => 'USD',
            'active' => true,
        ]);

        $this->run = BillingRun::create([
            'municipality_id' => $this->municipality->id,
            'run_number' => 'BR-202607-0001',
            'period_month' => '2026-07-01',
            'frequency' => 'monthly',
            'status' => 'completed',
            'invoice_count' => 1,
            'currency_totals' => ['USD' => 115.0],
            'run_at' => now(),
        ]);

        $this->invoice = Invoice::create([
            'municipality_id' => $this->municipality->id,
            'billing_run_id' => $this->run->id,
            'customer_id' => $customer->id,
            'invoice_number' => '202607-00001',
            'period_month' => '2026-07-01',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax_total' => 15,
            'total' => 115,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $this->invoice->lines()->create([
            'tr_code' => 'RATES',
            'description' => 'Property Rates — Testville',
            'quantity' => 1,
            'unit_amount' => 100,
            'amount' => 100,
            'tax_amount' => 15,
        ]);
    }

    public function test_invoice_pdf_streams_inline_for_a_municipality_member(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('documents.invoice', ['invoice' => $this->invoice]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));
    }

    public function test_invoice_pdf_downloads_as_an_attachment_when_requested(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('documents.invoice', ['invoice' => $this->invoice, 'download' => 1]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertDownload("invoice-{$this->invoice->invoice_number}.pdf");
    }

    public function test_users_from_another_municipality_are_denied(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('documents.invoice', ['invoice' => $this->invoice]))
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->get(route('documents.billing-run.post-billing', ['billingRun' => $this->run]))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('documents.invoice', ['invoice' => $this->invoice]))
            ->assertRedirect();
    }

    public function test_billing_run_report_and_batch_pdfs_render_for_a_completed_run(): void
    {
        foreach (['billing-run.post-billing', 'billing-run.pre-billing', 'billing-run.invoices'] as $route) {
            $response = $this->actingAs($this->member)
                ->get(route("documents.{$route}", ['billingRun' => $this->run]));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
        }
    }

    public function test_post_billing_documents_are_unavailable_for_draft_runs(): void
    {
        $draft = BillingRun::create([
            'municipality_id' => $this->municipality->id,
            'run_number' => 'BR-202607-0002',
            'period_month' => '2026-07-01',
            'frequency' => 'monthly',
            'status' => 'draft',
        ]);

        $this->actingAs($this->member)
            ->get(route('documents.billing-run.post-billing', ['billingRun' => $draft]))
            ->assertNotFound();

        $this->actingAs($this->member)
            ->get(route('documents.billing-run.invoices', ['billingRun' => $draft]))
            ->assertNotFound();
    }
}
