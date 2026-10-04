<?php

namespace App\Filament\Resources\FeeTableVersions;

use App\Actions\ApproveFeeTableVersion;
use App\Enums\FeePeriod;
use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Enums\TaxTreatment;
use App\Filament\Concerns\OnlyConfigurators;
use App\Filament\Resources\FeeTableVersions\Pages\ManageFeeTableVersions;
use App\Models\FeeTableVersion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Validation\ValidationException;

class FeeTableVersionResource extends Resource
{
    use OnlyConfigurators;

    protected static ?string $model = FeeTableVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|\UnitEnum|null $navigationGroup = 'Fees';

    protected static ?string $navigationLabel = 'Fee table versions';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Version')
                    ->description('Draft versions can be edited. Active versions are immutable - create a new draft to make changes.')
                    ->columns(3)
                    ->schema([
                        Select::make('fee_table_id')
                            ->label('Fee table')
                            ->relationship('feeTable', 'name')
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('version')
                            ->required()
                            ->numeric()
                            ->disabledOn('edit'),
                        TextInput::make('status')
                            ->default('draft')
                            ->disabled()
                            ->dehydrated(),
                        DatePicker::make('effective_from')
                            ->native(false)
                            ->helperText('Date this version takes effect. Leave blank to always apply once approved.'),
                        DatePicker::make('effective_until')
                            ->native(false)
                            ->helperText('Last day this version applies. Usually blank; the next approved version supersedes it.'),
                        Textarea::make('notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Fee lines')
                    ->description('Annual licence lines must stay Exempt. Our own service, admin and plate fees are Standard-rated (VAT applies).')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->columns(12)
                            ->disabled(fn (?FeeTableVersion $record): bool => $record !== null && ! $record->isEditable())
                            ->itemLabel(fn (array $state): string => self::summariseLine($state))
                            ->collapsed()
                            ->collapsible()
                            ->cloneable()
                            ->defaultItems(0)
                            ->addActionLabel('Add fee line')
                            ->schema([
                                TextInput::make('code')
                                    ->label('Code')
                                    ->placeholder('licence')
                                    ->required()
                                    ->maxLength(64)
                                    ->columnSpan(2)
                                    ->helperText('Short identifier used by the fee calculator (e.g. licence, admin, plates).'),
                                TextInput::make('label')
                                    ->label('Label on quote')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(5),
                                TextInput::make('amount_rand')
                                    ->label('Amount (R)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->default(0)
                                    ->required()
                                    ->prefix('R')
                                    ->columnSpan(2)
                                    ->dehydrated(false)
                                    ->afterStateHydrated(function (TextInput $component, $state, $record): void {
                                        $cents = $record?->amount_cents ?? $state;
                                        $component->state($cents !== null ? number_format($cents / 100, 2, '.', '') : null);
                                    }),
                                TextInput::make('amount_cents')
                                    ->default(0)
                                    ->dehydrated()
                                    ->extraAttributes(['style' => 'display:none'])
                                    ->columnSpan(0)
                                    ->hidden(),
                                Select::make('tax_treatment')
                                    ->options(TaxTreatment::class)
                                    ->default(TaxTreatment::Exempt)
                                    ->required()
                                    ->columnSpan(2),
                                Select::make('period')
                                    ->options(FeePeriod::class)
                                    ->default(FeePeriod::Annual)
                                    ->required()
                                    ->columnSpan(2),
                                Select::make('licence_category')
                                    ->label('Vehicle category')
                                    ->options(LicenceFeeCategory::class)
                                    ->nullable()
                                    ->columnSpan(3)
                                    ->helperText('Set for licence bands. Leave blank for our own service fees.'),
                                TextInput::make('tare_min_kg')
                                    ->label('Tare from (kg)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->nullable()
                                    ->columnSpan(2),
                                TextInput::make('tare_max_kg')
                                    ->label('Tare to (kg)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->nullable()
                                    ->columnSpan(2),
                                Toggle::make('client_visible')
                                    ->label('Show on client quote')
                                    ->default(true)
                                    ->columnSpan(3),
                                TextInput::make('service_type')
                                    ->label('Service type match')
                                    ->placeholder('register_and_license')
                                    ->nullable()
                                    ->columnSpan(3)
                                    ->helperText('Optional: restrict this line to applications of this service type.'),
                                TextInput::make('vehicle_category')
                                    ->label('Business category match')
                                    ->placeholder('commercial')
                                    ->nullable()
                                    ->columnSpan(3)
                                    ->helperText('Optional: restrict to passenger / commercial routing.'),
                                TextInput::make('request_type')
                                    ->label('Request type match')
                                    ->nullable()
                                    ->columnSpan(3),
                            ])
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::persistRand($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::persistRand($data)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('feeTable.province')
                    ->label('Province')
                    ->formatStateUsing(fn ($state): string => $state instanceof Province ? $state->label() : (string) $state)
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('feeTable.name')
                    ->label('Table')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('version')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'success' => 'active',
                        'warning' => 'superseded',
                    ])
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->date('j M Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('effective_until')
                    ->date('j M Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lines'),
                TextColumn::make('approved_at')
                    ->dateTime('j M Y H:i')
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Active',
                        'superseded' => 'Superseded',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('approve')
                    ->label('Approve & publish')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (FeeTableVersion $record): bool => $record->status === 'draft' && (int) $record->created_by !== (int) auth()->id())
                    ->requiresConfirmation()
                    ->modalDescription('Approving this version marks it active and supersedes any previously active version for the same province. Lines become immutable.')
                    ->action(function (FeeTableVersion $record): void {
                        try {
                            app(ApproveFeeTableVersion::class)->handle($record, auth()->user());

                            Notification::make()
                                ->title('Version approved.')
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title($exception->validator->errors()->first())
                                ->danger()
                                ->send();
                        }
                    }),
                DeleteAction::make()
                    ->visible(fn (FeeTableVersion $record): bool => $record->status === 'draft'),
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
            'index' => ManageFeeTableVersions::route('/'),
        ];
    }

    /**
     * Convert the operator-facing "Amount (R)" input back into integer cents
     * before the row is persisted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function persistRand(array $data): array
    {
        if (array_key_exists('amount_rand', $data)) {
            $data['amount_cents'] = (int) round(((float) $data['amount_rand']) * 100);
            unset($data['amount_rand']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function summariseLine(array $state): string
    {
        $parts = [];
        $parts[] = $state['code'] ?? 'new line';
        $parts[] = $state['label'] ?? '';

        if (isset($state['amount_cents'])) {
            $parts[] = 'R'.number_format(((int) $state['amount_cents']) / 100, 2);
        }

        if (! empty($state['tare_min_kg']) || ! empty($state['tare_max_kg'])) {
            $parts[] = ($state['tare_min_kg'] ?? '0').'-'.($state['tare_max_kg'] ?? '>').' kg';
        }

        return trim(implode(' - ', array_filter($parts, fn ($v) => $v !== '' && $v !== null)));
    }
}
