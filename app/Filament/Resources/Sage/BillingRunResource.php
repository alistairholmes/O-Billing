<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sage;

use App\Filament\Resources\Sage\Pages\ListBillingRuns;
use App\Models\Sage\BillingRun;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BillingRunResource extends SageResource
{
    protected static ?string $model = BillingRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Billing Runs';

    protected static ?string $modelLabel = 'billing run';

    protected static ?string $recordTitleAttribute = 'cBillingRunNumber';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('period'))
            ->defaultSort('idBillingRun', 'desc')
            ->columns([
                TextColumn::make('cBillingRunNumber')->label('Number')->searchable()->sortable(),
                TextColumn::make('period.dPeriodDate')->label('Period')->date('F Y')->sortable(),
                IconColumn::make('bBillingRunProcessed')->label('Processed')->boolean(),
                TextColumn::make('iSysDateProcessed')->label('Processed on')->dateTime('d M Y')->placeholder('—'),
                TextColumn::make('cUserNameProcessed')->label('Processed by')->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Billing run')->columns(3)->schema([
                TextEntry::make('cBillingRunNumber')->label('Number'),
                TextEntry::make('period.dPeriodDate')->label('Period')->date('F Y'),
                TextEntry::make('bBillingRunProcessed')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Processed' : 'Draft')
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
                TextEntry::make('dBillingCycleDate')->label('Billing cycle date')->dateTime('d M Y')->placeholder('—'),
                TextEntry::make('iSysDateCalculated')->label('Calculated on')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('iSysDateProcessed')->label('Processed on')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('cUserNameCalculated')->label('Calculated by')->placeholder('—'),
                TextEntry::make('cUserNameProcessed')->label('Processed by')->placeholder('—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingRuns::route('/'),
        ];
    }
}
