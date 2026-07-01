<?php

namespace App\Filament\Resources\BillingRuns\Tables;

use App\Models\BillingRun;
use App\Services\Billing\BillingRunService;
use App\Support\Currencies;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
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
                    ->color(fn (string $state) => $state === 'completed' ? 'success' : 'gray'),
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
                Action::make('generate')
                    ->label(fn (BillingRun $r) => $r->isCompleted() ? 'Re-run' : 'Generate invoices')
                    ->icon(Heroicon::OutlinedPlayCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Generate billing run')
                    ->modalDescription('Creates invoices using the tariffs for each property\'s suburb, in their billing currency, honouring this run\'s scope (services, account range, location range) and frequency. Re-running replaces this run\'s existing invoices.')
                    ->action(function (BillingRun $record): void {
                        $result = app(BillingRunService::class)->generate($record);

                        $totals = collect($result['currency_totals'])
                            ->map(fn ($a, $c) => Currencies::format($a, $c))
                            ->implode(', ');

                        Notification::make()
                            ->success()
                            ->title('Billing run complete')
                            ->body("{$result['invoice_count']} invoices generated. Total billed: ".($totals ?: '—'))
                            ->send();
                    }),
                \Filament\Actions\ViewAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
