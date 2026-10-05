<?php

namespace App\Filament\Resources\AuditEvents;

use App\Filament\Concerns\OnlyAuditors;
use App\Filament\Resources\AuditEvents\Pages\ManageAuditEvents;
use App\Models\AuditEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuditEventResource extends Resource
{
    use OnlyAuditors;

    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $recordTitleAttribute = 'summary';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('actor'))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label('Actor')
                    ->default('System')
                    ->description(fn (AuditEvent $event): ?string => $event->actor_role)
                    ->searchable(['name', 'email']),
                TextColumn::make('action')
                    ->badge()
                    ->searchable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->state(function (AuditEvent $event): string {
                        $parts = explode('\\', (string) $event->subject_type);
                        $tail = end($parts) ?: 'Record';

                        return $tail.' #'.$event->subject_id;
                    })
                    ->extraAttributes(['class' => 'font-mono text-xs text-gray-600']),
                TextColumn::make('summary')
                    ->wrap()
                    ->limit(140),
                TextColumn::make('ip')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->extraAttributes(['class' => 'font-mono text-xs']),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(fn () => AuditEvent::query()
                        ->distinct()
                        ->pluck('action', 'action')
                        ->toArray())
                    ->multiple(),
                SelectFilter::make('actor_role')
                    ->label('Role')
                    ->options([
                        'super_admin' => 'Super admin',
                        'customer_admin' => 'Operations admin',
                        'reviewer' => 'Reviewer',
                        'finance' => 'Finance',
                        'auditor' => 'Auditor',
                        'client_admin' => 'Client admin',
                        'client_user' => 'Client user',
                    ]),
                Filter::make('system')
                    ->label('Include system entries')
                    ->toggle()
                    ->default(true)
                    ->query(fn (Builder $query, array $data): Builder => $data['isActive']
                        ? $query
                        : $query->where('is_system', false)),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAuditEvents::route('/'),
        ];
    }
}
