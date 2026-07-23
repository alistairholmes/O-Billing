<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * Admin provisioning of panel users. Replaces the tinker workflow: create the
 * user here and tick the municipalities they may sign in to.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 90;

    protected static ?string $recordTitleAttribute = 'name';

    // Users belong to many municipalities, so this resource manages the full
    // user list rather than being scoped to the current tenant.
    protected static bool $isScopedToTenant = false;

    /** Only administrators may provision or manage panel users. */
    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->rule(Password::default())
                    ->helperText(fn (string $operation) => $operation === 'edit'
                        ? 'Leave blank to keep the current password.'
                        : 'Share this with the user; they can change it later.'),
                CheckboxList::make('municipalities')
                    ->relationship('municipalities', 'name')
                    ->default(fn () => array_filter([Filament::getTenant()?->getKey()]))
                    ->required()
                    ->helperText('The user can only sign in to the municipalities ticked here.'),
                Toggle::make('is_admin')
                    ->label('Administrator')
                    ->helperText('Administrators can manage panel users on this page.')
                    // Don't let an admin demote their own account and lock
                    // everyone out of user management.
                    ->disabled(fn (?User $record) => $record?->is(Filament::auth()->user()) ?? false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('municipalities.name')
                    ->label('Municipalities')
                    ->badge()
                    ->color('primary'),
                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    // Never let a user delete their own account from the panel.
                    ->hidden(fn (User $record) => $record->is(Filament::auth()->user())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
