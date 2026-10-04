<?php

namespace App\Filament\Widgets;

use App\Models\AuditEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentActivity extends TableWidget
{
    protected static ?string $heading = 'Recent activity';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AuditEvent::query()
                ->with('actor')
                ->latest('occurred_at'))
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No activity yet')
            ->emptyStateDescription('When reviewers accept, finance verifies, or an application moves stage you will see it here.')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->dateTime('d M H:i')
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label('Who')
                    ->default('System')
                    ->description(fn (AuditEvent $event): ?string => $event->actor_role),
                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace(['application.', 'document.', 'payment.', 'quote.'], '', $state)),
                TextColumn::make('summary')
                    ->wrap(),
                TextColumn::make('subject_id')
                    ->label('Reference')
                    ->formatStateUsing(function (AuditEvent $event): string {
                        $parts = explode('\\', (string) $event->subject_type);
                        $tail = end($parts) ?: 'Record';

                        return $tail.' #'.$event->subject_id;
                    })
                    ->color('gray')
                    ->extraAttributes(['class' => 'font-mono text-xs']),
            ]);
    }
}
