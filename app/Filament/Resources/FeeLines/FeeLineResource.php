<?php

namespace App\Filament\Resources\FeeLines;

use App\Enums\FeePeriod;
use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Enums\TaxTreatment;
use App\Filament\Concerns\OnlyConfigurators;
use App\Filament\Resources\FeeLines\Pages\ManageFeeLines;
use App\Models\FeeLine;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FeeLineResource extends Resource
{
    use OnlyConfigurators;

    protected static ?string $model = FeeLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = 'Fees';

    protected static ?string $navigationLabel = 'Fee lines';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query->with(['version.feeTable']);
            })
            ->defaultSort(fn (Builder $query): Builder => $query
                ->join('fee_table_versions', 'fee_table_versions.id', '=', 'fee_lines.fee_table_version_id')
                ->join('fee_tables', 'fee_tables.id', '=', 'fee_table_versions.fee_table_id')
                ->orderBy('fee_tables.province')
                ->orderBy('fee_table_versions.version', 'desc')
                ->orderBy('fee_lines.sort_order')
                ->orderBy('fee_lines.id')
                ->select('fee_lines.*')
            )
            ->groups([
                Group::make('version.feeTable.province')
                    ->label('Province')
                    ->getTitleFromRecordUsing(fn (FeeLine $record): string => $record->version?->feeTable?->province?->label() ?? '-'),
                Group::make('licence_category')
                    ->label('Vehicle category')
                    ->getTitleFromRecordUsing(fn (FeeLine $record): string => $record->licence_category?->label() ?? 'Other / service fee'),
                Group::make('code')
                    ->label('Code'),
            ])
            ->defaultGroup('version.feeTable.province')
            ->columns([
                TextColumn::make('version.feeTable.province')
                    ->label('Province')
                    ->formatStateUsing(fn ($state): string => $state instanceof Province ? $state->label() : (string) $state)
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('version.version')
                    ->label('Ver.')
                    ->numeric()
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('version.status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'active' => 'success',
                        'draft' => 'warning',
                        'superseded' => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('licence_category')
                    ->label('Vehicle category')
                    ->formatStateUsing(fn ($state): string => $state instanceof LicenceFeeCategory ? $state->label() : 'Other / service fee')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('tare_band')
                    ->label('Tare band')
                    ->state(fn (FeeLine $record): string => self::describeTareBand($record))
                    ->alignRight(),
                TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'licence' => 'info',
                        'registration', 'datafix' => 'warning',
                        default => 'gray',
                    })
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('label')
                    ->label('Label')
                    ->searchable()
                    ->wrap()
                    ->limit(70)
                    ->tooltip(fn (FeeLine $record): string => $record->label),
                TextInputColumn::make('amount_rand')
                    ->label('Amount (R)')
                    ->type('number')
                    ->rules(['numeric', 'min:0'])
                    ->extraAttributes(['step' => '0.01', 'class' => 'text-right tabular-nums'])
                    ->width('140px')
                    ->getStateUsing(fn (FeeLine $record): string => number_format($record->amount_cents / 100, 2, '.', ''))
                    ->updateStateUsing(function (FeeLine $record, $state): void {
                        if (! $record->version || $record->version->status !== 'draft') {
                            return;
                        }

                        $record->amount_cents = (int) round(((float) $state) * 100);
                        $record->save();
                    })
                    ->disabled(fn (FeeLine $record): bool => $record->version?->status !== 'draft'),
                TextColumn::make('tax_treatment')
                    ->label('Tax')
                    ->formatStateUsing(fn ($state): string => $state instanceof TaxTreatment ? $state->shortLabel() : '-')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state === TaxTreatment::Exempt => 'gray',
                        $state === TaxTreatment::ZeroRated => 'info',
                        $state === TaxTreatment::Standard => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('period')
                    ->label('Period')
                    ->formatStateUsing(fn ($state): string => $state instanceof FeePeriod ? $state->label() : '-')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('client_visible')
                    ->label('Visible')
                    ->disabled(fn (FeeLine $record): bool => $record->version?->status !== 'draft')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('province')
                    ->label('Province')
                    ->options(Province::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('version.feeTable', fn (Builder $q) => $q->where('province', $data['value']))
                        : $query),
                SelectFilter::make('status')
                    ->label('Version status')
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Active',
                        'superseded' => 'Superseded',
                    ])
                    ->default('draft')
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('version', fn (Builder $q) => $q->where('status', $data['value']))
                        : $query),
                SelectFilter::make('licence_category')
                    ->label('Vehicle category')
                    ->options(LicenceFeeCategory::class),
                SelectFilter::make('code')
                    ->label('Code')
                    ->options([
                        'licence' => 'Licence',
                        'registration' => 'Registration',
                        'datafix' => 'Datafix',
                        'admin' => 'Admin',
                        'plates' => 'Plates',
                        'runner' => 'Runner',
                    ]),
                SelectFilter::make('tax_treatment')
                    ->label('Tax treatment')
                    ->options(TaxTreatment::class),
            ])
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ])
            ->paginated([25, 50, 100, 250, 'all'])
            ->defaultPaginationPageOption(50);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFeeLines::route('/'),
        ];
    }

    private static function describeTareBand(FeeLine $record): string
    {
        if ($record->tare_min_kg === null && $record->tare_max_kg === null) {
            return '-';
        }

        if ($record->tare_min_kg !== null && $record->tare_max_kg === null) {
            return 'over '.number_format($record->tare_min_kg).' kg';
        }

        if ($record->tare_min_kg === null && $record->tare_max_kg !== null) {
            return 'up to '.number_format($record->tare_max_kg).' kg';
        }

        return number_format($record->tare_min_kg).'-'.number_format($record->tare_max_kg).' kg';
    }
}
