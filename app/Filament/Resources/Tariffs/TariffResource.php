<?php

namespace App\Filament\Resources\Tariffs;

use App\Filament\Resources\Tariffs\Pages\ManageTariffs;
use App\Models\Area;
use App\Models\Service;
use App\Models\ServiceType;
use App\Models\Tariff;
use App\Support\Currencies;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TariffResource extends Resource
{
    protected static ?string $model = Tariff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Billing Setup';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Tariffs (per suburb)';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('area_id')
                    ->label('Suburb')
                    ->options(fn () => Area::billingLevel()->with('parent')->get()
                        ->mapWithKeys(fn (Area $a) => [$a->id => $a->pathLabel()]))
                    ->searchable()
                    ->required()
                    ->native(false),
                Select::make('service_id')
                    ->label('Service')
                    ->options(fn () => Service::groupedOptions())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('rate')
                    ->label('Rate')
                    ->numeric()
                    ->required()
                    ->helperText(fn ($get) => match (Service::find($get('service_id'))?->serviceType?->billing_basis) {
                        ServiceType::BASIS_PER_PROPERTY_VALUE => 'Charge per 1 unit of property value, per month (e.g. 0.012 = 1.2c per R of value).',
                        ServiceType::BASIS_PER_HECTARE => 'Price per hectare. Charged on the customer\'s land size, which is captured in m² and converted (10,000 m² = 1 ha). On an annual service this is the whole year\'s rate.',
                        ServiceType::BASIS_PER_UNIT => 'Price per unit consumed.',
                        default => 'Fixed monthly charge.',
                    }),
                Select::make('currency')
                    ->options(fn () => collect(Filament::getTenant()->currencies())
                        ->mapWithKeys(fn ($c) => [$c => $c]))
                    ->default(fn () => Filament::getTenant()->base_currency)
                    ->required()
                    ->native(false),
                TextInput::make('tr_code')
                    ->label('TR code')
                    ->maxLength(50)
                    ->placeholder('e.g. 5000')
                    ->helperText('Sage transaction (revenue) code this charge posts to.'),
                DatePicker::make('effective_from'),
                Toggle::make('active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('area.name')
                    ->label('Suburb')
                    ->description(fn (Tariff $r) => $r->area?->parent?->pathLabel())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('service.name')
                    ->label('Service')
                    ->state(fn (Tariff $r) => $r->service?->displayName())
                    ->badge()
                    ->color('primary'),
                TextColumn::make('rate')
                    ->formatStateUsing(fn ($state, Tariff $r) => Currencies::format($state, $r->currency)),
                TextColumn::make('currency')->badge(),
                TextColumn::make('tr_code')
                    ->label('TR code')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('service_id')
                    ->label('Service')
                    ->options(fn () => Service::groupedOptions()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTariffs::route('/'),
        ];
    }
}
