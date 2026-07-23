<?php

namespace App\Filament\Resources\BillingRuns\Tables;

use App\Filament\Resources\BillingRuns\Actions\GenerateBillingRunAction;
use App\Filament\Resources\BillingRuns\Actions\PostToSageAction;
use App\Filament\Resources\BillingRuns\Actions\ReverseBillingRunAction;
use App\Models\BillingRun;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BillingRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('period_month', 'desc')
            ->columns([
                TextColumn::make('run_number')
                    ->label('Run #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('period_month')
                    ->label('Period')
                    ->date('F Y')
                    ->sortable(),
                TextColumn::make('frequency')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => BillingRun::frequencies()[$state] ?? $state),
                TextColumn::make('description')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success',
                        'reversed' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (BillingRun $r) => $r->isReversed() ? $r->reversal_reason : null),
                TextColumn::make('invoice_count')
                    ->label('Invoices')
                    ->badge(),
                TextColumn::make('total_billed')
                    ->label('Total billed')
                    ->state(fn (BillingRun $r) => $r->formattedCurrencyTotals()),
                TextColumn::make('run_at')
                    ->label('Generated')
                    ->dateTime()
                    ->placeholder('Not yet run')
                    ->toggleable(),
            ])
            ->recordActions([
                GenerateBillingRunAction::make(),
                PostToSageAction::make(),
                ReverseBillingRunAction::make(),
                \Filament\Actions\ViewAction::make(),
                // Only never-generated drafts can be deleted outright. A completed
                // run is undone with "Reverse run" (kept on record), and a run in
                // Sage must be corrected there.
                DeleteAction::make()
                    ->visible(fn (BillingRun $r) => ! $r->isCompleted() && ! $r->isReversed() && ! $r->isInSage()),
            ]);
    }
}
