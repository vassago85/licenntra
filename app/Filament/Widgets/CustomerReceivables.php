<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\OnlyFinanceOrAdmin;
use App\Models\ClientAccount;
use App\Models\Payment;
use App\Models\Quote;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Per-customer accounts-receivable position. Each row is a client account,
 * sorted by outstanding balance (accepted quotes minus verified payments).
 * Accounts with no activity in the last 90 days and no outstanding balance
 * are hidden to keep the list focused on money.
 */
class CustomerReceivables extends TableWidget
{
    use OnlyFinanceOrAdmin;

    protected static ?string $heading = 'Customers - outstanding payments';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -2;

    public function table(Table $table): Table
    {
        $balances = $this->computeBalances();

        $accountIds = array_filter(array_keys($balances), fn (int $id): bool => $balances[$id]['outstanding_cents'] > 0 || $balances[$id]['verified_90d_cents'] > 0);

        return $table
            ->query(fn (): Builder => ClientAccount::query()
                ->when(count($accountIds) === 0, fn ($q) => $q->whereRaw('1 = 0'))
                ->whereIn('id', $accountIds))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Nothing owed')
            ->emptyStateDescription('Every accepted quote has been paid in full.')
            ->columns([
                TextColumn::make('name')
                    ->label('Account')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ClientAccount $record): ?string => $record->contact_name),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? '-'),

                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->state(fn (ClientAccount $record): string => 'R'.number_format(($balances[$record->id]['outstanding_cents'] ?? 0) / 100, 2, '.', ' '))
                    ->color(fn (ClientAccount $record): string => ($balances[$record->id]['outstanding_cents'] ?? 0) > 0 ? 'warning' : 'success')
                    ->extraAttributes(['class' => 'font-mono font-medium'])
                    ->sortable(query: fn (Builder $query, string $direction) => $query),

                TextColumn::make('oldest')
                    ->label('Oldest invoice')
                    ->state(function (ClientAccount $record) use ($balances): string {
                        $days = $balances[$record->id]['oldest_days'] ?? null;

                        return $days === null ? '-' : $days.' days';
                    })
                    ->color(function (ClientAccount $record) use ($balances): string {
                        $days = $balances[$record->id]['oldest_days'] ?? null;

                        return match (true) {
                            $days === null => 'gray',
                            $days > 60 => 'danger',
                            $days > 30 => 'warning',
                            default => 'gray',
                        };
                    }),

                TextColumn::make('open_quotes')
                    ->label('Open quotes')
                    ->state(fn (ClientAccount $record): int => $balances[$record->id]['open_quotes'] ?? 0)
                    ->alignCenter(),

                TextColumn::make('paid_90d')
                    ->label('Paid (last 90d)')
                    ->state(fn (ClientAccount $record): string => 'R'.number_format(($balances[$record->id]['verified_90d_cents'] ?? 0) / 100, 2, '.', ' '))
                    ->color('success')
                    ->extraAttributes(['class' => 'font-mono']),

                TextColumn::make('contact_email')
                    ->label('Contact')
                    ->toggleable()
                    ->icon('heroicon-m-envelope')
                    ->copyable()
                    ->placeholder('-'),
            ])
            ->defaultSort(fn (Builder $query) => $query, 'asc')
            ->modifyQueryUsing(function (Builder $query) use ($balances, $accountIds) {
                if (count($accountIds) === 0) {
                    return $query;
                }

                $ordered = collect($accountIds)
                    ->sortByDesc(fn (int $id): int => $balances[$id]['outstanding_cents'] ?? 0)
                    ->values()
                    ->all();

                $placeholders = implode(',', array_map(fn (int $id): string => (string) $id, $ordered));

                if ($placeholders === '') {
                    return $query;
                }

                return $query->orderByRaw("CASE id {$this->buildCaseExpression($ordered)} END");
            });
    }

    /**
     * @param  list<int>  $orderedIds
     */
    private function buildCaseExpression(array $orderedIds): string
    {
        $cases = [];

        foreach ($orderedIds as $position => $id) {
            $cases[] = 'WHEN '.(int) $id.' THEN '.$position;
        }

        return implode(' ', $cases);
    }

    /**
     * @return array<int, array{outstanding_cents: int, oldest_days: int|null, open_quotes: int, verified_90d_cents: int}>
     */
    private function computeBalances(): array
    {
        $balances = [];

        $accepted = Quote::query()
            ->where('status', 'accepted')
            ->with(['lines:id,quote_id,client_price_cents', 'application:id,client_account_id'])
            ->get();

        foreach ($accepted as $quote) {
            if ($quote->application === null) {
                continue;
            }

            $accountId = (int) $quote->application->client_account_id;
            $quoted = (int) $quote->lines->sum('client_price_cents');
            $paid = (int) Payment::query()
                ->whereNotNull('verified_at')
                ->where('application_id', $quote->application_id)
                ->sum('amount_cents');
            $balance = max(0, $quoted - $paid);
            $acceptedAt = $quote->updated_at ?? $quote->created_at;
            $ageDays = $acceptedAt ? (int) $acceptedAt->diffInDays(Carbon::now()) : 0;

            $balances[$accountId] ??= ['outstanding_cents' => 0, 'oldest_days' => null, 'open_quotes' => 0, 'verified_90d_cents' => 0];

            $balances[$accountId]['outstanding_cents'] += $balance;

            if ($balance > 0 && ($balances[$accountId]['oldest_days'] === null || $ageDays > $balances[$accountId]['oldest_days'])) {
                $balances[$accountId]['oldest_days'] = $ageDays;
            }
        }

        $openByAccount = Quote::query()
            ->where('status', 'sent')
            ->with('application:id,client_account_id')
            ->get()
            ->groupBy(fn (Quote $q): int => (int) ($q->application?->client_account_id ?? 0));

        foreach ($openByAccount as $accountId => $quotes) {
            if ($accountId === 0) {
                continue;
            }

            $balances[(int) $accountId] ??= ['outstanding_cents' => 0, 'oldest_days' => null, 'open_quotes' => 0, 'verified_90d_cents' => 0];
            $balances[(int) $accountId]['open_quotes'] = $quotes->count();
        }

        $paid90dByAccount = Payment::query()
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', Carbon::now()->subDays(90))
            ->with('application:id,client_account_id')
            ->get()
            ->groupBy(fn (Payment $p): int => (int) ($p->application?->client_account_id ?? 0));

        foreach ($paid90dByAccount as $accountId => $payments) {
            if ($accountId === 0) {
                continue;
            }

            $balances[(int) $accountId] ??= ['outstanding_cents' => 0, 'oldest_days' => null, 'open_quotes' => 0, 'verified_90d_cents' => 0];
            $balances[(int) $accountId]['verified_90d_cents'] = (int) $payments->sum('amount_cents');
        }

        return $balances;
    }
}
