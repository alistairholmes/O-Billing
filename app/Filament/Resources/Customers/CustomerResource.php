<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\ManageCustomers;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Sage\SagePropertyWriter;
use App\Support\Currencies;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Properties';

    protected static ?string $modelLabel = 'property';

    protected static ?string $pluralModelLabel = 'properties';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('account_number')
                            ->required()
                            ->default(fn () => 'ACC-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT)),
                        TextInput::make('name')->label('Account holder')->required(),
                        Select::make('type')
                            ->options([
                                'residential' => 'Residential',
                                'business' => 'Business',
                                'government' => 'Government',
                            ])
                            ->default('residential')
                            ->required()
                            ->native(false),
                        Select::make('area_id')
                            ->label('Suburb')
                            ->options(fn () => Area::billingLevel()->with('parent')->get()
                                ->mapWithKeys(fn (Area $a) => [$a->id => $a->pathLabel()]))
                            ->searchable()
                            ->required()
                            ->native(false),
                        Select::make('currency')
                            ->options(fn () => collect(Filament::getTenant()->currencies())
                                ->mapWithKeys(fn ($c) => [$c => $c]))
                            ->default(fn () => Filament::getTenant()->base_currency)
                            ->required()
                            ->native(false),
                        TextInput::make('property_value')
                            ->label('Property value')
                            ->numeric()
                            ->helperText('Rateable value used for property-rates services.'),
                        TextInput::make('land_size')
                            ->label('Land size')
                            ->numeric()
                            ->suffix('m²')
                            ->helperText('Optional — from the valuation roll, if available.'),
                        TextInput::make('land_value')
                            ->label('Land value')
                            ->numeric()
                            ->helperText('Optional — site value, if available.'),
                        TextInput::make('improvement_value')
                            ->label('Improvement value')
                            ->numeric()
                            ->helperText('Optional — value of buildings/improvements, if available.'),
                    ]),
                Section::make('Contact')
                    ->columns(2)
                    ->schema([
                        TextInput::make('email')->email(),
                        TextInput::make('phone'),
                        TextInput::make('address')->columnSpanFull(),
                    ]),
                Section::make('Billing')
                    ->schema([
                        Select::make('services')
                            ->label('Subscribed services')
                            ->relationship('services', 'name')
                            ->options(fn () => Service::groupedOptions())
                            ->multiple()
                            ->preload()
                            ->helperText('Only these services are charged on this account. For a service with variants (e.g. Property Rates), pick the one that applies to this property.'),
                        Toggle::make('active')->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account_number')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('area.name')
                    ->label('Suburb')
                    ->description(fn (Customer $r) => $r->area?->parent?->pathLabel()),
                TextColumn::make('type')->badge(),
                TextColumn::make('property_value')
                    ->formatStateUsing(fn ($state, Customer $r) => $state ? Currencies::format($state, $r->currency) : '—'),
                TextColumn::make('land_size')
                    ->label('Land size')
                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 0).' m²' : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('land_value')
                    ->formatStateUsing(fn ($state, Customer $r) => $state ? Currencies::format($state, $r->currency) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('improvement_value')
                    ->formatStateUsing(fn ($state, Customer $r) => $state ? Currencies::format($state, $r->currency) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('currency')->badge(),
                TextColumn::make('services_count')->label('Services')->counts('services')->badge()->color('primary'),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('area_id')
                    ->label('Suburb')
                    ->options(fn () => Area::billingLevel()->pluck('name', 'id')),
                SelectFilter::make('type')
                    ->options([
                        'residential' => 'Residential',
                        'business' => 'Business',
                        'government' => 'Government',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                self::pushToSageAction(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Create this property in the Sage database so it shows up in Sage — as a
     * property record when the company runs the property module, or as one
     * debtor account per service when it keeps properties in the debtors
     * ledger. Writes to the configured write database.
     */
    private static function pushToSageAction(): Action
    {
        return Action::make('pushToSage')
            ->label('Send to Sage')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Send property to Sage')
            ->modalDescription(function (): string {
                $database = config('database.connections.sage_write.database');

                return app(SagePropertyWriter::class)->targetsPropertyModule()
                    ? "Creates this property (and its owner, if not already in Sage) in \"{$database}\", so it appears in the Sage property list."
                    : "This Sage company keeps properties as debtor accounts. Creates one Sage debtor account per subscribed service in \"{$database}\" (e.g. STAND-DEVR-…), so the property appears in the Sage debtors ledger and can be billed.";
            })
            ->modalSubmitActionLabel('Send to Sage')
            ->action(function (Customer $record): void {
                $result = app(SagePropertyWriter::class)
                    ->pushProperty($record->load(['area', 'services.serviceType']));

                if (! ($result['ok'] ?? false)) {
                    Notification::make()->danger()->title('Could not send to Sage')
                        ->body($result['error'] ?? 'Unknown error')->persistent()->send();

                    return;
                }

                if (($result['mode'] ?? null) === 'ledger') {
                    $parts = ['Created '.implode(', ', $result['created']).' in "'.$result['database'].'".'];
                    if ($result['existing'] !== []) {
                        $parts[] = 'Already there: '.implode(', ', $result['existing']).'.';
                    }
                    if ($result['unmapped'] !== []) {
                        $parts[] = 'No Sage account type for: '.implode(', ', $result['unmapped']).'.';
                    }

                    Notification::make()->success()->title("Property {$result['stand']} created in Sage")
                        ->body(implode(' ', $parts))->persistent()->send();

                    return;
                }

                $owner = $result['owner_created'] ? 'new owner created' : 'linked to existing owner';
                Notification::make()->success()->title('Property created in Sage')
                    ->body("Erf {$result['erf']} added to \"{$result['database']}\" — property #{$result['property_id']}, {$owner}, {$result['services']} service(s) linked.")
                    ->persistent()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCustomers::route('/'),
        ];
    }
}
