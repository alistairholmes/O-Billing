<?php

namespace App\Filament\Resources\BillingRuns\Pages;

use App\Filament\Resources\BillingRuns\Actions\BillingRunPdfActions;
use App\Filament\Resources\BillingRuns\Actions\GenerateBillingRunAction;
use App\Filament\Resources\BillingRuns\Actions\PostToSageAction;
use App\Filament\Resources\BillingRuns\Actions\ReverseBillingRunAction;
use App\Filament\Resources\BillingRuns\BillingRunResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewBillingRun extends ViewRecord
{
    protected static string $resource = BillingRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GenerateBillingRunAction::make(),
            PostToSageAction::make(),
            ReverseBillingRunAction::make(),
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
