<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Resources\Invoices\Actions\InvoicePdfActions;
use App\Filament\Resources\Invoices\Actions\PushInvoiceToSageAction;
use App\Models\Invoice;
use App\Support\Currencies;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('invoice_number')->searchable()->sortable(),
                TextColumn::make('customer.name')->label('Property')->searchable(),
                TextColumn::make('customer.area.name')->label('Suburb')->toggleable(),
                TextColumn::make('period_month')->label('Month')->date('M Y')->sortable(),
                TextColumn::make('subtotal')
                    ->formatStateUsing(fn ($state, Invoice $r) => Currencies::format($state, $r->currency)),
                TextColumn::make('tax_total')
                    ->label('Tax')
                    ->formatStateUsing(fn ($state, Invoice $r) => Currencies::format($state, $r->currency)),
                TextColumn::make('total')
                    ->formatStateUsing(fn ($state, Invoice $r) => Currencies::format($state, $r->currency))
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('currency')->badge(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success',
                        'cancelled' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('billing_run_id')
                    ->label('Billing run')
                    ->relationship('billingRun', 'period_month'),
                SelectFilter::make('status')
                    ->options([
                        'issued' => 'Issued',
                        'paid' => 'Paid',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                InvoicePdfActions::print(),
                InvoicePdfActions::download(),
                PushInvoiceToSageAction::make(),
            ]);
    }
}
