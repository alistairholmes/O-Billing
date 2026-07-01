<?php

namespace App\Filament\Resources\Services;

use App\Filament\Resources\Services\Pages\ManageServices;
use App\Models\Service;
use App\Models\ServiceType;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Billing Setup';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('service_type_id')
                    ->label('Service group')
                    ->options(fn () => ServiceType::where('active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->native(false),
                TextInput::make('name')
                    ->required()
                    ->placeholder('e.g. High Density')
                    ->maxLength(255),
                TextInput::make('code')->maxLength(50),
                Toggle::make('is_default')
                    ->label('Default service')
                    ->helperText('The group\'s plain service when it has no real variants — shown without a variant suffix on invoices.')
                    ->default(false),
                Toggle::make('taxable')
                    ->helperText('Whether this service is subject to tax (e.g. VAT) when billed.')
                    ->default(true),
                Toggle::make('active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('serviceType.name')
                    ->label('Service group')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('code')->badge(),
                TextColumn::make('taxable')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('active')->boolean(),
                TextColumn::make('tariffs_count')->label('Tariffs')->counts('tariffs')->badge(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('service_type_id')
                    ->label('Service group')
                    ->relationship('serviceType', 'name'),
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
            'index' => ManageServices::route('/'),
        ];
    }
}
