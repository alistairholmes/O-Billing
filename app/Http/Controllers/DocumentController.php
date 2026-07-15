<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BillingRun;
use App\Models\Invoice;
use App\Services\Billing\BillingRunService;
use App\Support\Tenancy\CurrentMunicipality;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves invoices and billing reports as PDF documents. Each route streams the
 * PDF inline (the browser's viewer handles printing) or as an attachment when
 * ?download=1 is passed. These routes live outside the Filament panel, so
 * tenant access is checked explicitly and the municipality scope set by hand.
 */
class DocumentController extends Controller
{
    /** A single customer invoice document. */
    public function invoice(Request $request, Invoice $invoice): Response
    {
        $this->authorizeMunicipality($request, $invoice);

        $invoice->load(['lines.service', 'customer.area', 'billingRun']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'municipality' => $invoice->municipality,
        ]);

        return $this->respond($request, $pdf, "invoice-{$invoice->invoice_number}.pdf");
    }

    /** Every invoice of a completed run in one document, one invoice per page. */
    public function billingRunInvoices(Request $request, BillingRun $billingRun): Response
    {
        $this->authorizeMunicipality($request, $billingRun);
        abort_unless($billingRun->isCompleted(), 404);

        $invoices = $billingRun->invoices()
            ->with(['lines.service', 'customer.area'])
            ->orderBy('invoice_number')
            ->get();

        $pdf = Pdf::loadView('pdf.invoices-batch', [
            'run' => $billingRun,
            'invoices' => $invoices,
            'municipality' => $billingRun->municipality,
        ]);

        return $this->respond($request, $pdf, "invoices-{$billingRun->run_number}.pdf");
    }

    /** Pre-billing report: the dry-run projection of what a run would bill. */
    public function preBillingReport(Request $request, BillingRun $billingRun): Response
    {
        $this->authorizeMunicipality($request, $billingRun);

        // The dry-run queries tenant-scoped models, so it must run after the
        // municipality scope has been set above.
        $preview = app(BillingRunService::class)->preview($billingRun);

        $pdf = Pdf::loadView('pdf.pre-billing-report', [
            'run' => $billingRun,
            'preview' => $preview,
            'municipality' => $billingRun->municipality,
        ]);

        return $this->respond($request, $pdf, "pre-billing-{$billingRun->run_number}.pdf");
    }

    /** Post-billing report: what a completed run actually billed. */
    public function postBillingReport(Request $request, BillingRun $billingRun): Response
    {
        $this->authorizeMunicipality($request, $billingRun);
        abort_unless($billingRun->isCompleted(), 404);

        $invoices = $billingRun->invoices()
            ->with('customer.area')
            ->orderBy('invoice_number')
            ->get();

        $pdf = Pdf::loadView('pdf.post-billing-report', [
            'run' => $billingRun,
            'invoices' => $invoices,
            'municipality' => $billingRun->municipality,
        ]);

        return $this->respond($request, $pdf, "post-billing-{$billingRun->run_number}.pdf");
    }

    /**
     * Deny access unless the user belongs to the record's municipality, then
     * bind that municipality as the current tenant so global scopes apply.
     */
    private function authorizeMunicipality(Request $request, Model $record): void
    {
        abort_unless($request->user()->canAccessTenant($record->municipality), 403);

        app(CurrentMunicipality::class)->set($record->municipality_id);
    }

    private function respond(Request $request, PdfDocument $pdf, string $filename): Response
    {
        $pdf->setPaper('a4');

        return $request->boolean('download')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }
}
