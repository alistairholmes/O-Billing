<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingRuns\Actions;

use App\Models\BillingRun;
use App\Services\Billing\BillingRunService;
use App\Services\Billing\DuplicateBillingRunException;
use App\Support\Currencies;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use LogicException;

/**
 * "Generate invoices" / "Re-run" — shared by the runs table and the run view
 * page. Duplicate-billing conflicts (another completed run overlapping this
 * one's scope and period) surface as a persistent error notification instead of
 * generating, so customers can never be billed twice by accident.
 */
final class GenerateBillingRunAction
{
    public static function make(): Action
    {
        return Action::make('generate')
            ->label(fn (BillingRun $r) => $r->isCompleted() ? 'Re-run' : 'Generate invoices')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->visible(fn (BillingRun $r) => ! $r->isReversed() && ! $r->isInSage())
            ->requiresConfirmation()
            ->modalHeading('Generate billing run')
            ->modalDescription('Creates invoices using the tariffs for each property\'s suburb, in their billing currency, honouring this run\'s scope (services, account range, location range) and frequency. Re-running replaces this run\'s existing invoices.')
            ->action(function (BillingRun $record): void {
                try {
                    $result = app(BillingRunService::class)->generate($record);
                } catch (DuplicateBillingRunException $e) {
                    Notification::make()
                        ->danger()
                        ->persistent()
                        ->title('Duplicate billing prevented')
                        ->body($e->getMessage())
                        ->send();

                    return;
                } catch (LogicException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Billing run not generated')
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                $totals = collect($result['currency_totals'])
                    ->map(fn ($a, $c) => Currencies::format($a, $c))
                    ->implode(', ');

                Notification::make()
                    ->success()
                    ->title('Billing run complete')
                    ->body("{$result['invoice_count']} invoices generated. Total billed: ".($totals ?: '—'))
                    ->send();
            });
    }
}
