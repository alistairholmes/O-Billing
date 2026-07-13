<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sage;

use App\Filament\Resources\Sage\Pages\ListProperties;
use App\Models\Sage\Property;
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

class PropertyResource extends SageResource
{
    protected static ?string $model = Property::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Properties';

    protected static ?string $modelLabel = 'property';

    protected static ?string $recordTitleAttribute = 'cERFNo';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['area', 'ratingCategory']))
            ->defaultSort('cERFNo')
            ->columns([
                TextColumn::make('cERFNo')->label('Erf / stand')->searchable()->sortable(),
                TextColumn::make('area.cAreaDescription')->label('Area')->sortable(),
                TextColumn::make('ratingCategory.cCategory')->label('Category')->toggleable(),
                TextColumn::make('marketValue')->label('Rateable value')
                    ->state(fn (Property $r) => $r->marketValue())
                    ->formatStateUsing(fn ($state) => $state ? Currencies::format($state, 'USD') : '—')
                    ->alignEnd(),
                TextColumn::make('portions_count')->label('Portions')->counts('portions')->badge()->color('primary'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Property')->columns(3)->schema([
                TextEntry::make('cERFNo')->label('Erf / stand'),
                TextEntry::make('cSGCode')->label('SG code')->placeholder('—'),
                TextEntry::make('cDeedsNumber')->label('Deed number')->placeholder('—'),
                TextEntry::make('area.cAreaDescription')->label('Area'),
                TextEntry::make('ratingCategory.cCategory')->label('Rating category')->placeholder('—'),
                TextEntry::make('iNoPortions')->label('Portions')->placeholder('—'),
                TextEntry::make('address')->label('Address')->columnSpanFull()
                    ->state(fn (Property $r) => $r->addressLabel() ?: '—'),
            ]),
            Section::make('Valuation')->columns(4)->schema([
                TextEntry::make('marketValue')->label('Rateable value')
                    ->state(fn (Property $r) => $r->marketValue())
                    ->formatStateUsing(fn ($state) => Currencies::format($state, 'USD')),
                TextEntry::make('fLandValue')->label('Land value')->formatStateUsing(fn ($state) => Currencies::format($state, 'USD')),
                TextEntry::make('fImprovementValue')->label('Improvement value')->formatStateUsing(fn ($state) => Currencies::format($state, 'USD')),
                TextEntry::make('fLandSize')->label('Land size')->formatStateUsing(fn ($state) => number_format((float) $state, 0).' m²'),
            ]),
            Section::make('Portions & consumers')->schema([
                RepeatableEntry::make('portions')
                    ->hiddenLabel()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('cPortion')->label('Portion'),
                        TextEntry::make('consumer.Account')->label('Consumer account')->placeholder('—'),
                        TextEntry::make('consumer.Name')->label('Consumer')->placeholder('—'),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProperties::route('/'),
        ];
    }
}
