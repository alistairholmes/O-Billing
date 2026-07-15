<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingRuns\Actions;

use App\Models\BillingRun;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Print / download actions for a billing run's PDF documents: the pre-billing
 * report (draft runs), the post-billing report and the batch of all invoices
 * (completed runs). "Print" streams the PDF inline in a new tab so it can be
 * printed from the browser's viewer; "Download" fetches it as an attachment.
 */
final class BillingRunPdfActions
{
    /** @param 'pre-billing'|'post-billing' $report */
    public static function printReport(string $report): Action
    {
        return Action::make("print-{$report}")
            ->label('Print report')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->visible(fn (BillingRun $record) => self::reportAvailable($report, $record))
            ->url(fn (BillingRun $record) => self::reportUrl($report, $record))
            ->openUrlInNewTab();
    }

    /** @param 'pre-billing'|'post-billing' $report */
    public static function downloadReport(string $report): Action
    {
        return Action::make("download-{$report}")
            ->label('Download report')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (BillingRun $record) => self::reportAvailable($report, $record))
            ->url(fn (BillingRun $record) => self::reportUrl($report, $record, download: true));
    }

    /** One PDF containing every invoice of a completed run, for bulk printing/mailing. */
    public static function downloadInvoices(): Action
    {
        return Action::make('downloadRunInvoices')
            ->label('Download invoices (PDF)')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->visible(fn (BillingRun $record) => $record->isCompleted() && $record->invoice_count > 0)
            ->url(fn (BillingRun $record) => route('documents.billing-run.invoices', [
                'billingRun' => $record, 'download' => 1,
            ]));
    }

    /** The pre-billing projection applies to unprocessed runs; the register to completed ones. */
    private static function reportAvailable(string $report, BillingRun $record): bool
    {
        return $report === 'pre-billing' ? ! $record->isCompleted() : $record->isCompleted();
    }

    private static function reportUrl(string $report, BillingRun $record, bool $download = false): string
    {
        return route("documents.billing-run.{$report}", array_filter([
            'billingRun' => $record,
            'download' => $download ? 1 : null,
        ]));
    }
}
