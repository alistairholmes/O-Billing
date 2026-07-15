<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\Actions\InvoicePdfActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Support\Currencies;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InvoicePdfActions::print(),
            InvoicePdfActions::download(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')
                ->columns(3)
                ->schema([
                    TextEntry::make('invoice_number'),
                    TextEntry::make('customer.name')->label('Property'),
                    TextEntry::make('customer.account_number')->label('Account'),
                    TextEntry::make('period_month')->date('F Y'),
                    TextEntry::make('currency')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('subtotal')
                        ->formatStateUsing(fn ($state, Invoice $r) => Currencies::format($state, $r->currency)),
                    TextEntry::make('tax_total')->label('Tax')
                        ->formatStateUsing(fn ($state, Invoice $r) => Currencies::format($state, $r->currency)),
                    TextEntry::make('total')->weight('bold')
                        ->formatStateUsing(fn ($state, Invoice $r) => Currencies::format($state, $r->currency)),
                ]),
        ]);
    }
}
