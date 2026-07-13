<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sage;

use App\Filament\Resources\Sage\Pages\ListTariffs;
use App\Models\Sage\Tariff;
use App\Support\Currencies;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TariffResource extends SageResource
{
    protected static ?string $model = Tariff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Tariffs';

    protected static ?string $modelLabel = 'tariff';

    protected static ?string $recordTitleAttribute = 'cRateTariffDescription';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['service', 'billingTrCode']))
            ->defaultSort('cRateTariff')
            ->columns([
                TextColumn::make('cRateTariff')->label('Code')->searchable()->sortable(),
                TextColumn::make('cRateTariffDescription')->label('Tariff')->searchable()->sortable(),
                TextColumn::make('service.Name')->label('Service')->sortable(),
                TextColumn::make('billingTrCode.Code')->label('GL code')->toggleable(),
                TextColumn::make('bands_count')->label('Bands')->counts('bands')->badge()
                    ->color(fn ($state) => $state > 1 ? 'warning' : 'gray')
                    ->tooltip(fn ($state) => $state > 1 ? 'Block tariff (multiple rate bands)' : null),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tariff')->columns(3)->schema([
                TextEntry::make('cRateTariff')->label('Code'),
                TextEntry::make('cRateTariffDescription')->label('Tariff'),
                TextEntry::make('service.Name')->label('Service'),
                TextEntry::make('billingTrCode.Code')->label('Billing GL code')->placeholder('—'),
                TextEntry::make('billingTrCode.Tax')->label('Taxable')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
            ]),
            Section::make('Rate bands')
                ->description('A single band is a flat rate; several rising bands make a block tariff.')
                ->schema([
                    RepeatableEntry::make('bands')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('fToValue')->label('Up to (units)')
                                ->formatStateUsing(fn ($state) => (float) $state > 0 ? number_format((float) $state, 0) : 'Flat'),
                            TextEntry::make('fBandAmount')->label('Rate')
                                ->formatStateUsing(fn ($state) => Currencies::format($state, 'USD')),
                            TextEntry::make('fromPeriod.dPeriodDate')->label('Effective from')
                                ->date('M Y')->placeholder('—'),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTariffs::route('/'),
        ];
    }
}
