<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingSchedules\Pages;

use App\Filament\Resources\BillingSchedules\BillingScheduleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBillingSchedule extends EditRecord
{
    protected static string $resource = BillingScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
