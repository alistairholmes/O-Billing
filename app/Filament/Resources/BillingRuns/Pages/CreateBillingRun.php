<?php

namespace App\Filament\Resources\BillingRuns\Pages;

use App\Filament\Resources\BillingRuns\BillingRunResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBillingRun extends CreateRecord
{
    protected static string $resource = BillingRunResource::class;

    /** Drop the "Create & create another" button — keep just Create and Cancel. */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCancelFormAction(),
        ];
    }
}
