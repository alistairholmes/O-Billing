<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingSchedules\Pages;

use App\Filament\Resources\BillingSchedules\BillingScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBillingSchedule extends CreateRecord
{
    protected static string $resource = BillingScheduleResource::class;
}
