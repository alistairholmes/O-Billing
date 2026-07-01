<?php

namespace App\Filament\Resources\BillingRuns\Schemas;

use App\Models\Area;
use App\Models\BillingRun;
use App\Models\Customer;
use App\Models\Service;
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
                            ->options(fn () => self::serviceOptions())
                            ->columns(2)
                            ->bulkToggleable()
                            ->searchable()
                            ->columnSpanFull()
                            ->helperText('Tick the services to bill. None ticked = all services.'),
                        Select::make('account_from')
                            ->label('From account')
                            ->options(fn () => self::accountOptions())
                            ->searchable()
                            ->helperText('Optional: first account in the range.'),
                        Select::make('account_to')
                            ->label('To account')
                            ->options(fn () => self::accountOptions())
                            ->searchable()
                            ->helperText('Optional: last account in the range.'),
                        Select::make('area_from_id')
                            ->label('From location')
                            ->options(fn () => self::locationOptions())
                            ->searchable()
                            ->helperText('Optional: first suburb in the range.'),
                        Select::make('area_to_id')
                            ->label('To location')
                            ->options(fn () => self::locationOptions())
                            ->searchable()
                            ->helperText('Optional: last suburb in the range.'),
                    ]),
            ]);
    }

    /** @return array<int, string> service id => "Group (Variant)" */
    private static function serviceOptions(): array
    {
        return Service::query()
            ->where('active', true)
            ->with('serviceType')
            ->get()
            ->mapWithKeys(fn (Service $s) => [$s->id => $s->displayName()])
            ->all();
    }

    /** @return array<string, string> account_number => "ACC-1001 — Name" */
    private static function accountOptions(): array
    {
        return Customer::query()
            ->orderBy('account_number')
            ->get(['account_number', 'name'])
            ->mapWithKeys(fn (Customer $c) => [$c->account_number => "{$c->account_number} — {$c->name}"])
            ->all();
    }

    /** @return array<int, string> area id => suburb path label */
    private static function locationOptions(): array
    {
        return Area::billingLevel()
            ->with('parent')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Area $a) => [$a->id => $a->pathLabel()])
            ->all();
    }
}
