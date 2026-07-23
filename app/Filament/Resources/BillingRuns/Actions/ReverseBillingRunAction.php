<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingRuns\Actions;

use App\Models\BillingRun;
use App\Services\Billing\BillingRunService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use LogicException;

/**
 * "Reverse run" — undoes a completed run made by mistake: deletes every invoice
 * it generated so customers are not charged, and keeps the run on record as
 * "reversed" with the operator's reason. Unavailable once the run has been
 * queued or posted to Sage (correct there with a credit note instead).
 */
final class ReverseBillingRunAction
{
    public static function make(): Action
    {
        return Action::make('reverse')
            ->label('Reverse run')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (BillingRun $r) => $r->isCompleted() && ! $r->isInSage())
            ->requiresConfirmation()
            ->modalHeading('Reverse billing run')
            ->modalDescription(fn (BillingRun $r) => "Deletes all {$r->invoice_count} invoice(s) this run generated so customers are not charged. The run stays on record, marked as reversed. This cannot be undone.")
            ->modalSubmitActionLabel('Reverse run')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason for reversal')
                    ->placeholder('e.g. Wrong tariff applied to high-density refuse')
                    ->required()
                    ->maxLength(500),
            ])
            ->action(function (BillingRun $record, array $data): void {
                try {
                    app(BillingRunService::class)->reverse($record, $data['reason']);
                } catch (LogicException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot reverse run')
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Billing run reversed')
                    ->body("Run {$record->run_number} was reversed and its invoices deleted. Customers will not be charged.")
                    ->send();
            });
    }
}
