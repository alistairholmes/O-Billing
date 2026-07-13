<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sage;

use App\Filament\Resources\Sage\Pages\ListServiceGroups;
use App\Models\Sage\Service;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The municipal-billing (`_mtbl`) schema has no service-groups table; services
 * are defined directly in `_ccg_EB_Services`. This browser therefore lists those
 * services. (The class/route name is kept as "service-groups" for URL stability.)
 */
class ServiceGroupResource extends SageResource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Services';

    protected static ?string $modelLabel = 'service';

    protected static ?string $recordTitleAttribute = 'Name';

    /** Sage `_ccg_EB_Services.CalculationMethod` codes. */
    public const CALC_METHODS = [
        1 => 'Fixed charge',
        2 => 'Metered / consumption',
        3 => 'Rated',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('Name')
            ->columns([
                TextColumn::make('Name')->searchable()->sortable(),
                TextColumn::make('Description')->limit(50)->toggleable(),
                TextColumn::make('CalculationMethod')->label('Calculation')
                    ->formatStateUsing(fn ($state) => self::CALC_METHODS[(int) $state] ?? "#{$state}"),
                TextColumn::make('MeasurableUnit')->label('Unit')->placeholder('—')->toggleable(),
                TextColumn::make('tariffs_count')->label('Tariffs')->counts('tariffs')->badge()->color('primary'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Service')->columns(3)->schema([
                TextEntry::make('Name'),
                TextEntry::make('CalculationMethod')->label('Calculation')
                    ->formatStateUsing(fn ($state) => self::CALC_METHODS[(int) $state] ?? "#{$state}"),
                TextEntry::make('MeasurableUnit')->label('Unit')->placeholder('—'),
                TextEntry::make('Description')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Rate tariffs')->schema([
                RepeatableEntry::make('tariffs')
                    ->hiddenLabel()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('cRateTariff')->label('Code'),
                        TextEntry::make('cRateTariffDescription')->label('Tariff'),
                        TextEntry::make('bands_count')->label('Bands')
                            ->state(fn ($record) => $record->bands()->count()),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceGroups::route('/'),
        ];
    }
}
