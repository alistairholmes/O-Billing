<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sage;

use App\Filament\Resources\Sage\Pages\ListClients;
use App\Models\Sage\Client;
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

class ClientResource extends SageResource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Customers';

    protected static ?string $modelLabel = 'customer';

    protected static ?string $recordTitleAttribute = 'Name';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('Account')
            ->columns([
                TextColumn::make('Account')->searchable()->sortable(),
                TextColumn::make('Name')->searchable()->limit(40),
                TextColumn::make('Telephone')->label('Phone')->toggleable(),
                TextColumn::make('EMail')->label('E-mail')->toggleable()->limit(30),
                TextColumn::make('DCBalance')->label('Balance')->sortable()
                    ->formatStateUsing(fn ($state) => Currencies::format($state, 'USD'))
                    ->alignEnd(),
                TextColumn::make('portions_count')->label('Portions')->counts('portions')->badge(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer')->columns(3)->schema([
                TextEntry::make('Account'),
                TextEntry::make('Name'),
                TextEntry::make('Addressee')->placeholder('—'),
                TextEntry::make('Telephone')->label('Phone')->placeholder('—'),
                TextEntry::make('Telephone2')->label('Phone 2')->placeholder('—'),
                TextEntry::make('EMail')->label('E-mail')->placeholder('—'),
                TextEntry::make('DCBalance')->label('Balance')
                    ->formatStateUsing(fn ($state) => Currencies::format($state, 'USD')),
                TextEntry::make('address')->label('Physical address')->columnSpan(2)
                    ->state(fn (Client $r) => $r->physicalAddress() ?: '—'),
            ]),
            Section::make('Property portions billed')->schema([
                RepeatableEntry::make('portions')
                    ->hiddenLabel()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('cPortion')->label('Portion'),
                        TextEntry::make('property.cERFNo')->label('Erf / stand')->placeholder('—'),
                        TextEntry::make('property.area.cAreaDescription')->label('Area')->placeholder('—'),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
        ];
    }
}
