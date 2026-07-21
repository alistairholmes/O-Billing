<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingSchedules;

use App\Filament\Resources\BillingSchedules\Pages\CreateBillingSchedule;
use App\Filament\Resources\BillingSchedules\Pages\EditBillingSchedule;
use App\Filament\Resources\BillingSchedules\Pages\ListBillingSchedules;
use App\Filament\Resources\BillingSchedules\Schemas\BillingScheduleForm;
use App\Filament\Resources\BillingSchedules\Tables\BillingSchedulesTable;
use App\Models\BillingSchedule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BillingScheduleResource extends Resource
{
    protected static ?string $model = BillingSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Billing schedules';

    public static function form(Schema $schema): Schema
    {
        return BillingScheduleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BillingSchedulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingSchedules::route('/'),
            'create' => CreateBillingSchedule::route('/create'),
            'edit' => EditBillingSchedule::route('/{record}/edit'),
        ];
    }
}
