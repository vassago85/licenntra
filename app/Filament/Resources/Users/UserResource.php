<?php

namespace App\Filament\Resources\Users;

use App\Actions\OffboardStaffMember;
use App\Enums\OffboardReason;
use App\Exceptions\OffboardingNotAllowed;
use App\Filament\Concerns\OnlyConfigurators;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;
use Throwable;

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
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (User $user): string => self::statusLabel($user))
                    ->color(fn (User $user): string => self::statusColor($user))
                    ->description(fn (User $user): ?string => self::statusDescription($user)),
                TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'deactivated' => 'Deactivated',
                        'offboarded' => 'Offboarded',
                        'anonymised' => 'Anonymised',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? null) {
                            'active' => $query->where('is_active', true)->whereNull('offboarded_at'),
                            'deactivated' => $query->where('is_active', false)->whereNull('offboarded_at'),
                            'offboarded' => $query->whereNotNull('offboarded_at')->whereNull('anonymised_at'),
                            'anonymised' => $query->whereNotNull('anonymised_at'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (User $user): bool => ! $user->isOffboarded()),
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedArchiveBoxXMark)
                    ->requiresConfirmation()
                    ->modalDescription('They stay in the audit log and keep every attribution, but they cannot sign in until reactivated. Use this for temporary absences; for permanent leavers use Offboard.')
                    ->visible(fn (User $user): bool => $user->is_active && ! $user->isOffboarded() && $user->id !== auth()->id())
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
                    ->visible(fn (User $user): bool => ! $user->is_active && ! $user->isOffboarded())
                    ->action(function (User $user): void {
                        $user->update(['is_active' => true]);
                        Notification::make()->title($user->name.' activated')->success()->send();
                    }),
                Action::make('offboard')
                    ->label('Offboard')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->modalHeading(fn (User $user): string => "Offboard {$user->name}")
                    ->modalDescription('Immediately revokes access, strips roles, kills sessions and 2FA, and starts the '.User::RETENTION_YEARS.'-year retention clock. This cannot be undone — rehires must be created as a new user.')
                    ->modalSubmitActionLabel('Offboard')
                    ->visible(fn (User $user): bool => $user->isLicensingStaff() && ! $user->isOffboarded() && $user->id !== auth()->id())
                    ->schema([
                        Select::make('reason')
                            ->label('Reason')
                            ->options(OffboardReason::options())
                            ->required(),
                        Textarea::make('note')
                            ->label('Note')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('Optional context — kept with the audit record.'),
                    ])
                    ->action(function (User $user, array $data, OffboardStaffMember $action): void {
                        try {
                            $action->handle(
                                $user,
                                OffboardReason::from($data['reason']),
                                $data['note'] ?? null,
                                auth()->user(),
                            );
                        } catch (OffboardingNotAllowed $exception) {
                            Notification::make()
                                ->title('Cannot offboard')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Offboarding failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($user->name.' offboarded')
                            ->body('Record retained until '.$user->fresh()->retentionEndsAt()?->format('d M Y').'.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function statusLabel(User $user): string
    {
        if ($user->isAnonymised()) {
            return 'Anonymised';
        }

        if ($user->isOffboarded()) {
            return 'Offboarded';
        }

        return $user->is_active ? 'Active' : 'Deactivated';
    }

    private static function statusColor(User $user): string
    {
        if ($user->isAnonymised()) {
            return 'gray';
        }

        if ($user->isOffboarded()) {
            return 'danger';
        }

        return $user->is_active ? 'success' : 'warning';
    }

    private static function statusDescription(User $user): ?string
    {
        if ($user->isAnonymised()) {
            return 'PII scrubbed on '.$user->anonymised_at?->format('d M Y');
        }

        if ($user->isOffboarded()) {
            $reason = $user->offboard_reason?->label();
            $purgeOn = $user->retentionEndsAt()?->format('d M Y');

            return trim(($reason ? $reason.' · ' : '').($purgeOn ? "purges on {$purgeOn}" : ''));
        }

        return null;
    }

    private static function wouldStrandAdmins(User $candidate): bool
    {
        $adminRoleIds = Role::query()->whereIn('name', ['super_admin', 'customer_admin'])->pluck('id');

        if (! $candidate->roles()->whereIn('id', $adminRoleIds)->exists()) {
            return false;
        }

        $remaining = User::query()
            ->where('is_active', true)
            ->whereNull('offboarded_at')
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
