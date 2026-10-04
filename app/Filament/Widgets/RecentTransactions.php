<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\OnlyFinanceOrAdmin;
use App\Models\Payment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The last 15 payment events (verified or waiting), with the account and
 * application they belong to. The money-side companion to RecentActivity,
 * which shows audit-level actions.
 */
class RecentTransactions extends TableWidget
{
    use OnlyFinanceOrAdmin;

    protected static ?string $heading = 'Transactions';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Payment::query()
                ->with(['application:id,reference,client_account_id', 'application.clientAccount:id,name', 'verifier:id,name'])
                ->latest('created_at'))
            ->paginated([15, 50])
            ->defaultPaginationPageOption(15)
            ->emptyStateHeading('No transactions yet')
            ->emptyStateDescription('Verified payments and uploads awaiting verification will appear here.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Captured')
                    ->dateTime('d M H:i')
                    ->sortable()
                    ->description(fn (Payment $record): string => $record->verified_at
                        ? 'Verified '.$record->verified_at->diffForHumans()
                        : 'Awaiting verification'),

                TextColumn::make('application.clientAccount.name')
                    ->label('Account')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('application.reference')
                    ->label('Application')
                    ->searchable()
                    ->extraAttributes(['class' => 'font-mono text-xs'])
                    ->placeholder('-'),

                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => 'R'.number_format($state / 100, 2, '.', ' '))
                    ->extraAttributes(['class' => 'font-mono font-medium text-right'])
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),

                TextColumn::make('reference')
                    ->label('Reference')
                    ->extraAttributes(['class' => 'font-mono text-xs'])
                    ->copyable()
                    ->placeholder('-'),

                TextColumn::make('verified')
                    ->label('Status')
                    ->state(fn (Payment $record): string => $record->verified_at ? 'Verified' : 'Waiting')
                    ->badge()
                    ->colors([
                        'success' => 'Verified',
                        'warning' => 'Waiting',
                    ]),

                TextColumn::make('verifier.name')
                    ->label('Verified by')
                    ->toggleable()
                    ->placeholder('-'),
            ])
            ->filters([
                TernaryFilter::make('verified_at')
                    ->label('Verified')
                    ->placeholder('Any')
                    ->trueLabel('Verified only')
                    ->falseLabel('Awaiting only')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('verified_at'),
                        false: fn (Builder $q): Builder => $q->whereNull('verified_at'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
            ]);
    }
}
