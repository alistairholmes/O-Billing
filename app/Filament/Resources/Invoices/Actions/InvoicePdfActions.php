<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Actions;

use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Print / download actions for a single invoice. Both point at the invoice PDF
 * route: "Print" opens it inline in a new tab (printed from the browser's PDF
 * viewer), "Download" fetches it as an attachment.
 */
final class InvoicePdfActions
{
    public static function print(): Action
    {
        return Action::make('printInvoice')
            ->label('Print')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->url(fn (Invoice $record) => route('documents.invoice', ['invoice' => $record]))
            ->openUrlInNewTab();
    }

    public static function download(): Action
    {
        return Action::make('downloadInvoice')
            ->label('Download PDF')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->url(fn (Invoice $record) => route('documents.invoice', ['invoice' => $record, 'download' => 1]));
    }
}
