<?php

namespace App\Filament\Resources\ClientAccounts;

use App\Enums\BillingMode;
use App\Enums\ClientAccountType;
use App\Filament\Concerns\OnlyConfigurators;
use App\Filament\Resources\ClientAccounts\Pages\ManageClientAccounts;
use App\Models\ClientAccount;
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

class ClientAccountResource extends Resource
{
    use OnlyConfigurators;

    protected static ?string $model = ClientAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|\UnitEnum|null $navigationGroup = 'Access';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('type')
                    ->label('Primary type')
                    ->options(ClientAccountType::class)
                    ->required()
                    ->helperText('Drives the account\'s default label and the paperwork fields (BRN, proxy) that print on ALV / RLV submissions.'),
                Select::make('additional_types')
                    ->label('Also operates as')
                    ->multiple()
                    ->options(ClientAccountType::class)
                    ->helperText('Optional. Add every other role this account plays — e.g. a dealership that also runs a rental fleet picks "Fleet operator" here so the portal shows the fleet section.')
                    ->dehydrateStateUsing(function (?array $state, $get): ?array {
                        $primary = $get('type');

                        $values = collect($state ?? [])
                            ->filter(fn ($value): bool => $value !== null && $value !== '' && $value !== $primary)
                            ->unique()
                            ->values()
                            ->all();

                        return $values === [] ? null : $values;
                    }),
                TextInput::make('status')
                    ->required()
                    ->default('active'),
                Toggle::make('quote_acceptance_allowed')
                    ->required(),
                TextInput::make('markup_basis_points')
                    ->required()
                    ->numeric()
                    ->default(0),
                Select::make('billing_mode')
                    ->label('Billing mode')
                    ->options(BillingMode::class)
                    ->default(BillingMode::PayPerTransaction)
                    ->required()
                    ->live()
                    ->helperText('Pay per transaction = fee verified before documents move on. Account statement = documents go out immediately, fee goes on a monthly statement.'),
                TextInput::make('payment_terms_days')
                    ->label('Payment terms (days)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(120)
                    ->visible(fn ($get): bool => $get('billing_mode') === BillingMode::AccountStatement->value)
                    ->helperText('Days from statement date until payment is due. Typical dealership terms are 30 days.'),
                TextInput::make('credit_limit_cents')
                    ->label('Credit limit (R, in cents)')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn ($get): bool => $get('billing_mode') === BillingMode::AccountStatement->value)
                    ->helperText('Optional. Leave blank for no cap.'),
                TextInput::make('contact_name'),
                TextInput::make('contact_email')
                    ->email(),
                TextInput::make('contact_phone')
                    ->tel(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('status')
                    ->searchable(),
                IconColumn::make('quote_acceptance_allowed')
                    ->boolean(),
                TextColumn::make('markup_basis_points')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('billing_mode')
                    ->label('Billing')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof BillingMode ? $state->shortLabel() : (string) $state)
                    ->color(fn ($state): string => $state === BillingMode::AccountStatement ? 'info' : 'gray'),
                TextColumn::make('payment_terms_days')
                    ->label('Terms (days)')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('contact_name')
                    ->searchable(),
                TextColumn::make('contact_email')
                    ->searchable(),
                TextColumn::make('contact_phone')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
            'index' => ManageClientAccounts::route('/'),
        ];
    }
}
