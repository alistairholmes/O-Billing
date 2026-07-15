<?php

namespace App\Filament\Resources\BillingRuns\RelationManagers;

use App\Filament\Resources\Invoices\Actions\InvoicePdfActions;
use App\Models\Invoice;
use App\Support\Currencies;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Invoices';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('invoice_number')
            ->columns([
                TextColumn::make('invoice_number')->searchable(),
                TextColumn::make('customer.name')->label('Property')->searchable(),
                TextColumn::make('customer.account_number')->label('Account')->toggleable(),
                TextColumn::make('subtotal')
                    ->formatStateUsing(fn ($state, Invoice $r) => Currencies::format($state, $r->currency)),
                TextColumn::make('tax_total')
                    ->label('Tax')
                    ->formatStateUsing(fn ($state, Invoice $r) => Currencies::format($state, $r->currency)),
                TextColumn::make('total')
                    ->formatStateUsing(fn ($state, Invoice $r) => Currencies::format($state, $r->currency))
                    ->weight('bold'),
                TextColumn::make('currency')->badge(),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                InvoicePdfActions::print(),
                InvoicePdfActions::download(),
            ]);
    }
}
