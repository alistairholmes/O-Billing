<?php

namespace App\Filament\Resources\ServiceTypes;

use App\Filament\Resources\ServiceTypes\Pages\ManageServiceTypes;
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

class ServiceTypeResource extends Resource
{
    protected static ?string $model = ServiceType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Billing Setup';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Service Groups';

    protected static ?string $modelLabel = 'service group';

    protected static ?string $pluralModelLabel = 'service groups';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->maxLength(50),
                Select::make('billing_basis')
                    ->options(ServiceType::billingBases())
                    ->default(ServiceType::BASIS_FLAT)
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('unit_label')
                    ->label('Unit label')
                    ->placeholder('e.g. bin, kL')
                    ->visible(fn ($get) => $get('billing_basis') === ServiceType::BASIS_PER_UNIT),
                Toggle::make('active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge(),
                TextColumn::make('billing_basis')
                    ->label('Basis')
                    ->formatStateUsing(fn (string $state) => ServiceType::billingBases()[$state] ?? $state)
                    ->badge()
                    ->color('primary'),
                IconColumn::make('active')->boolean(),
                TextColumn::make('services_count')->label('Services')->counts('services')->badge()->color('primary'),
                TextColumn::make('tariffs_count')->label('Tariffs')->counts('tariffs')->badge(),
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
            'index' => ManageServiceTypes::route('/'),
        ];
    }
}
