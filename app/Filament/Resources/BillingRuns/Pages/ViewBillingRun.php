<?php

namespace App\Filament\Resources\BillingRuns\Pages;

use App\Filament\Resources\BillingRuns\Actions\BillingRunPdfActions;
use App\Filament\Resources\BillingRuns\Actions\PostToSageAction;
use App\Filament\Resources\BillingRuns\BillingRunResource;
use App\Models\BillingRun;
use App\Services\Billing\BillingRunService;
use App\Support\Currencies;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewBillingRun extends ViewRecord
{
    protected static string $resource = BillingRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label(fn (BillingRun $r) => $r->isCompleted() ? 'Re-run' : 'Generate invoices')
                ->icon(Heroicon::OutlinedPlayCircle)
                ->color('success')
                ->requiresConfirmation()
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
            PostToSageAction::make(),
            ActionGroup::make([
                BillingRunPdfActions::printReport('pre-billing'),
                BillingRunPdfActions::downloadReport('pre-billing'),
                BillingRunPdfActions::printReport('post-billing'),
                BillingRunPdfActions::downloadReport('post-billing'),
                BillingRunPdfActions::downloadInvoices(),
            ])
                ->label('Print / PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->button()
                ->color('gray'),
        ];
    }
}
