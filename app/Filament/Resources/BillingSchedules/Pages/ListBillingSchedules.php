<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingSchedules\Pages;

use App\Filament\Resources\BillingSchedules\BillingScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBillingSchedules extends ListRecords
{
    protected static string $resource = BillingScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
