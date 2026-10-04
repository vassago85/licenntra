<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\OnlyConfigurators;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    use OnlyConfigurators;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Access';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->icon(Heroicon::OutlinedUserCircle)
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('email')
                        ->label('Email address')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true),
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? bcrypt($state) : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Leave blank to keep the current password.'),
                ])
                ->columns(2),
            Section::make('Access')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->schema([
                    Select::make('roles')
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->required()
                        ->helperText('Client roles are scoped to a client account; staff roles are not.'),
                    Select::make('client_account_id')
                        ->label('Client account')
                        ->relationship('clientAccount', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText('Set only for client admins and client users.'),
                    Toggle::make('is_active')
                        ->label('Can sign in')
                        ->default(true)
                        ->inline(false),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (User $user): ?string => $user->email),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge(),
                TextColumn::make('clientAccount.name')
                    ->label('Client account')
                    ->placeholder('—')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedArchiveBoxXMark)
                    ->requiresConfirmation()
                    ->modalDescription('They stay in the audit log and keep every attribution, but they cannot sign in until reactivated.')
                    ->visible(fn (User $user): bool => $user->is_active && $user->id !== auth()->id())
                    ->action(function (User $user): void {
                        if (self::wouldStrandAdmins($user)) {
                            Notification::make()
                                ->title('Last authorised admin')
                                ->body('At least one active super or customer admin must remain.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $user->update(['is_active' => false]);
                        Notification::make()->title($user->name.' deactivated')->success()->send();
                    }),
                Action::make('activate')
                    ->label('Activate')
                    ->color('success')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->requiresConfirmation()
                    ->visible(fn (User $user): bool => ! $user->is_active)
                    ->action(function (User $user): void {
                        $user->update(['is_active' => true]);
                        Notification::make()->title($user->name.' activated')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function wouldStrandAdmins(User $candidate): bool
    {
        $adminRoleIds = Role::query()->whereIn('name', ['super_admin', 'customer_admin'])->pluck('id');

        if (! $candidate->roles()->whereIn('id', $adminRoleIds)->exists()) {
            return false;
        }

        $remaining = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $candidate->id)
            ->whereHas('roles', fn ($query) => $query->whereIn('id', $adminRoleIds))
            ->count();

        return $remaining === 0;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
