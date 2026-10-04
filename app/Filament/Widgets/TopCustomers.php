<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\OnlyFinanceOrAdmin;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\Payment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Who moved the most money in the last 90 days. Ranks client accounts by
 * verified payment total and shows throughput (applications submitted) and
 * average application value alongside.
 */
class TopCustomers extends TableWidget
{
    use OnlyFinanceOrAdmin;

    protected static ?string $heading = 'Top customers - last 90 days';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    public function table(Table $table): Table
    {
        $since = Carbon::now()->subDays(90);
        $metrics = $this->computeMetrics($since);

        $ranked = collect($metrics)
            ->sortByDesc('revenue_cents')
            ->take(10)
            ->keys()
            ->all();

        return $table
            ->query(fn (): Builder => ClientAccount::query()
                ->when(count($ranked) === 0, fn ($q) => $q->whereRaw('1 = 0'))
                ->whereIn('id', $ranked))
            ->paginated(false)
            ->emptyStateHeading('No revenue yet in the last 90 days')
            ->emptyStateDescription('As payments are verified they will show up here.')
            ->columns([
                TextColumn::make('rank')
                    ->label('#')
                    ->state(function (ClientAccount $record) use ($ranked): string {
                        $position = array_search($record->id, $ranked, true);

                        return $position === false ? '-' : '#'.((int) $position + 1);
                    })
                    ->color('gray')
                    ->extraAttributes(['class' => 'font-mono text-xs']),

                TextColumn::make('name')
                    ->label('Account')
                    ->searchable()
                    ->description(fn (ClientAccount $record): ?string => $record->contact_name),

                TextColumn::make('applications_count')
                    ->label('Applications (90d)')
                    ->state(fn (ClientAccount $record): int => $metrics[$record->id]['applications'] ?? 0)
                    ->alignCenter(),

                TextColumn::make('revenue')
                    ->label('Verified revenue')
                    ->state(fn (ClientAccount $record): string => 'R'.number_format(($metrics[$record->id]['revenue_cents'] ?? 0) / 100, 2, '.', ' '))
                    ->extraAttributes(['class' => 'font-mono font-medium']),

                TextColumn::make('avg_value')
                    ->label('Avg per application')
                    ->state(function (ClientAccount $record) use ($metrics): string {
                        $apps = $metrics[$record->id]['applications'] ?? 0;
                        $rev = $metrics[$record->id]['revenue_cents'] ?? 0;

                        if ($apps === 0) {
                            return '-';
                        }

                        return 'R'.number_format(($rev / $apps) / 100, 2, '.', ' ');
                    })
                    ->extraAttributes(['class' => 'font-mono']),

                TextColumn::make('last_activity')
                    ->label('Last activity')
                    ->state(function (ClientAccount $record) use ($metrics): string {
                        $when = $metrics[$record->id]['last_activity'] ?? null;

                        return $when ? $when->diffForHumans() : '-';
                    })
                    ->color('gray'),
            ])
            ->modifyQueryUsing(function (Builder $query) use ($ranked): Builder {
                if (count($ranked) === 0) {
                    return $query;
                }

                $cases = [];

                foreach ($ranked as $position => $id) {
                    $cases[] = 'WHEN '.(int) $id.' THEN '.$position;
                }

                return $query->orderByRaw('CASE id '.implode(' ', $cases).' END');
            });
    }

    /**
     * @return array<int, array{applications: int, revenue_cents: int, last_activity: ?Carbon}>
     */
    private function computeMetrics(Carbon $since): array
    {
        $metrics = [];

        $applications = Application::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('client_account_id, COUNT(*) as c, MAX(created_at) as last_app')
            ->groupBy('client_account_id')
            ->get();

        foreach ($applications as $row) {
            $metrics[(int) $row->client_account_id] = [
                'applications' => (int) $row->c,
                'revenue_cents' => 0,
                'last_activity' => $row->last_app ? Carbon::parse($row->last_app) : null,
            ];
        }

        $payments = Payment::query()
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', $since)
            ->with('application:id,client_account_id')
            ->get()
            ->groupBy(fn (Payment $p): int => (int) ($p->application?->client_account_id ?? 0));

        foreach ($payments as $accountId => $group) {
            if ($accountId === 0) {
                continue;
            }

            $metrics[(int) $accountId] ??= ['applications' => 0, 'revenue_cents' => 0, 'last_activity' => null];
            $metrics[(int) $accountId]['revenue_cents'] = (int) $group->sum('amount_cents');

            $lastPayment = $group->sortByDesc('verified_at')->first()?->verified_at;

            if ($lastPayment && ($metrics[(int) $accountId]['last_activity'] === null || $lastPayment->greaterThan($metrics[(int) $accountId]['last_activity']))) {
                $metrics[(int) $accountId]['last_activity'] = $lastPayment;
            }
        }

        return $metrics;
    }
}
