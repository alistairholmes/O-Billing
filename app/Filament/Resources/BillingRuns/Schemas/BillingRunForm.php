<?php

namespace App\Filament\Resources\BillingRuns\Schemas;

use App\Filament\Support\BillingScopeOptions;
use App\Models\BillingRun;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BillingRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Run')
                    ->columns(2)
                    ->schema([
                        TextInput::make('run_number')
                            ->label('Billing run number')
                            ->default(fn () => BillingRun::nextRunNumber())
                            ->required()
                            ->maxLength(255),
                        Select::make('frequency')
                            ->options(BillingRun::frequencies())
                            ->default('monthly')
                            ->required()
                            ->native(false)
                            ->helperText('Scales the charge: quarterly ×3, annually ×12, weekly ~¼.'),
                        DatePicker::make('period_month')
                            ->label('Billing period start')
                            ->helperText('Invoices are dated from this month.')
                            ->default(now()->startOfMonth())
                            ->required(),
                        TextInput::make('description')
                            ->placeholder('e.g. June 2026 monthly rates run')
                            ->maxLength(255),
                    ]),
                Section::make('Scope')
                    ->description('Leave everything blank to bill all active properties for all services.')
                    ->collapsible()
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
                        Select::make('account_from')
                            ->label('From account')
                            ->options(fn () => BillingScopeOptions::accounts())
                            ->searchable()
                            ->helperText('Optional: first account in the range.'),
                        Select::make('account_to')
                            ->label('To account')
                            ->options(fn () => BillingScopeOptions::accounts())
                            ->searchable()
                            ->helperText('Optional: last account in the range.'),
                        Select::make('area_from_id')
                            ->label('From location')
                            ->options(fn () => BillingScopeOptions::locations())
                            ->searchable()
                            ->helperText('Optional: first suburb in the range.'),
                        Select::make('area_to_id')
                            ->label('To location')
                            ->options(fn () => BillingScopeOptions::locations())
                            ->searchable()
                            ->helperText('Optional: last suburb in the range.'),
                    ]),
            ]);
    }
}
