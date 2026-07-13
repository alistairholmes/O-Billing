<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sage;

use App\Filament\Resources\Sage\Pages\ListAreas;
use App\Models\Sage\Area;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AreaResource extends SageResource
{
    protected static ?string $model = Area::class;

    protected static string|BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Areas';

    protected static ?string $modelLabel = 'area';

    protected static ?string $recordTitleAttribute = 'cAreaDescription';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('cArea')
            ->columns([
                TextColumn::make('cArea')->label('Code')->searchable()->sortable(),
                TextColumn::make('cAreaDescription')->label('Area name')->searchable()->sortable(),
                TextColumn::make('properties_count')->label('Properties')->counts('properties')->badge()->color('primary'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAreas::route('/'),
        ];
    }
}
