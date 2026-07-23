<?php

namespace App\Filament\Resources\BillingRuns\Pages;

use App\Filament\Resources\BillingRuns\BillingRunResource;
use App\Models\BillingRun;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBillingRun extends EditRecord
{
    protected static string $resource = BillingRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Only never-generated drafts can be deleted; completed runs are
            // undone with "Reverse run" and runs in Sage are corrected there.
            DeleteAction::make()
                ->visible(fn (BillingRun $r) => ! $r->isCompleted() && ! $r->isReversed() && ! $r->isInSage()),
        ];
    }
}
