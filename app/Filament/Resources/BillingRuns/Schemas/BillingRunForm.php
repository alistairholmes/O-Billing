<?php

namespace App\Filament\Resources\BillingRuns\Schemas;

use App\Filament\Support\BillingScopeOptions;
use App\Models\BillingRun;
use App\Services\Billing\BillingRunService;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                            ->required()
                            // Early duplicate warning: refuse to save a run whose
                            // scope overlaps a completed run for the same month
                            // (generation applies the same guard authoritatively).
                            ->rules([
                                fn (Get $get, ?BillingRun $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                    $probe = new BillingRun([
                                        'period_month' => $value,
                                        'frequency' => $get('frequency') ?? 'monthly',
                                        'service_ids' => $get('service_ids') ?? [],
                                        'account_from' => $get('account_from'),
                                        'account_to' => $get('account_to'),
                                        'area_from_id' => $get('area_from_id'),
                                        'area_to_id' => $get('area_to_id'),
                                    ]);

                                    $conflicts = app(BillingRunService::class)->conflictingRuns($probe, $record?->id);

                                    if ($conflicts->isNotEmpty()) {
                                        $fail(
                                            'Completed run(s) '.$conflicts->pluck('run_number')->implode(', ')
                                            .' already bill an overlapping scope for this period (or were generated today).'
                                            .' Reverse that run first if it was a mistake, or narrow this run\'s scope.'
                                        );
                                    }
                                },
                            ]),
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
