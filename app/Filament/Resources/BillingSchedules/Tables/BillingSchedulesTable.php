<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingSchedules\Tables;

use App\Models\BillingSchedule;
use App\Services\Billing\BillingScheduleRunner;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class BillingSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('frequency')->badge()
                    ->formatStateUsing(fn (string $state) => Str::headline($state)),
                ToggleColumn::make('active'),
                IconColumn::make('auto_post')->label('Auto-post')->boolean(),
                TextColumn::make('next_run_at')->label('Next run')->dateTime('d M Y')->placeholder('—')->sortable(),
                TextColumn::make('last_run_at')->label('Last run')->since()->placeholder('—')->toggleable(),
                TextColumn::make('occurrences_count')->label('Runs')->badge()->alignEnd(),
            ])
            ->recordActions([
                Action::make('runNow')
                    ->label('Run now')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Run this schedule now')
                    ->modalDescription(fn (BillingSchedule $record) => $record->auto_post
                        ? 'Generates a billing run now and queues it to post to Sage.'
                        : 'Generates a billing run now — you can review and post it afterwards.')
                    ->action(function (BillingSchedule $record): void {
                        $run = app(BillingScheduleRunner::class)->fire($record);

                        Notification::make()
                            ->success()
                            ->title("Run {$run->run_number} created")
                            ->body("{$run->invoice_count} invoice(s)".($record->auto_post ? ' — queued to post to Sage.' : '.'))
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
