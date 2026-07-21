<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingSchedules\Schemas;

use App\Filament\Support\BillingScopeOptions;
use App\Models\BillingRun;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BillingScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Schedule')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. Monthly rates')
                        ->columnSpanFull(),
                    Toggle::make('active')
                        ->default(true)
                        ->helperText('Only active schedules fire.'),
                    Toggle::make('auto_post')
                        ->label('Post to Sage automatically')
                        ->helperText('On: each run is generated AND queued to post to Sage. Off: someone reviews and posts it.'),
                ]),
            Section::make('When it runs')
                ->columns(2)
                ->schema([
                    Select::make('frequency')
                        ->options(BillingRun::frequencies())
                        ->default('monthly')
                        ->required()
                        ->native(false)
                        ->live()
                        ->helperText('Also sets how the charge is raised (quarterly ×3, annually ×12, weekly ~¼).'),
                    Select::make('day_mode')
                        ->label('Run on')
                        ->options(['last' => 'Last day of the month', 'specific' => 'A specific day of the month'])
                        ->default('last')
                        ->native(false)
                        ->live()
                        ->visible(fn (Get $get): bool => $get('frequency') !== 'weekly'),
                    TextInput::make('day_of_month')
                        ->label('Day of month')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(28)
                        ->helperText('1–28 (capped so every month has it).')
                        ->visible(fn (Get $get): bool => $get('frequency') !== 'weekly' && $get('day_mode') === 'specific')
                        ->required(fn (Get $get): bool => $get('day_mode') === 'specific'),
                    DatePicker::make('start_date')
                        ->label('Start date')
                        ->default(now()->startOfMonth())
                        ->required(),
                    DatePicker::make('end_date')
                        ->label('End date')
                        ->helperText('Leave blank to run indefinitely.'),
                    TextInput::make('max_occurrences')
                        ->label('Limit occurrences')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Leave blank for unlimited.'),
                ]),
            Section::make('Scope')
                ->description('Leave everything blank to bill all active properties for all services.')
                ->collapsible()
                ->collapsed()
                ->columns(2)
                ->schema([
                    CheckboxList::make('service_ids')
                        ->label('Services')
                        ->options(fn () => BillingScopeOptions::services())
                        ->columns(2)
                        ->bulkToggleable()
                        ->searchable()
                        ->columnSpanFull()
                        ->helperText('Tick the services to bill. None ticked = all services.'),
                    Select::make('account_from')->label('From account')
                        ->options(fn () => BillingScopeOptions::accounts())->searchable(),
                    Select::make('account_to')->label('To account')
                        ->options(fn () => BillingScopeOptions::accounts())->searchable(),
                    Select::make('area_from_id')->label('From location')
                        ->options(fn () => BillingScopeOptions::locations())->searchable(),
                    Select::make('area_to_id')->label('To location')
                        ->options(fn () => BillingScopeOptions::locations())->searchable(),
                ]),
        ]);
    }
}
