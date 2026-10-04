<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\OnlyFinanceOrAdmin;
use App\Models\Application;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\QuoteLine;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Owner-facing cash position at a glance. Interprets "outstanding" as:
 * accepted quotes whose application has not yet been paid in full. Age is
 * measured from the quote acceptance date; the application's payment stage
 * (payment_verified) closes the balance.
 */
class ReceivablesSummary extends StatsOverviewWidget
{
    use OnlyFinanceOrAdmin;

    protected ?string $heading = 'Money';

    protected ?string $description = 'Verified in, committed out, still owed.';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -3;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $receivables = $this->receivables();
        $verifiedThisMonth = Payment::query()
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', Carbon::now()->startOfMonth())
            ->sum('amount_cents');
        $verifiedLastMonth = Payment::query()
            ->whereNotNull('verified_at')
            ->whereBetween('verified_at', [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth(),
            ])->sum('amount_cents');
        $openQuoteTotal = $this->openQuoteTotal();
        $openQuoteCount = Quote::query()->where('status', 'sent')->count();

        $delta = $verifiedLastMonth === 0
            ? ($verifiedThisMonth > 0 ? '+ new activity' : 'no change')
            : $this->formatDelta($verifiedThisMonth, $verifiedLastMonth);

        return [
            Stat::make('Outstanding', $this->asRand($receivables['total_cents']))
                ->description($receivables['oldest_days'] === null
                    ? 'Nothing owed right now'
                    : 'Oldest '.$receivables['oldest_days'].' days  -  '.$receivables['account_count'].' account'.($receivables['account_count'] === 1 ? '' : 's'))
                ->color($receivables['total_cents'] > 0 ? 'warning' : 'success'),

            Stat::make('Verified this month', $this->asRand($verifiedThisMonth))
                ->description($delta.' vs last month')
                ->color('success'),

            Stat::make('Open quotes with the client', (string) $openQuoteCount)
                ->description($openQuoteCount > 0
                    ? $this->asRand($openQuoteTotal).' committed if accepted'
                    : 'No quotes awaiting a decision')
                ->color($openQuoteCount > 0 ? 'info' : 'gray'),

            Stat::make('Last payment', $this->describeLastPayment())
                ->description($this->describeLastPaymentAmount())
                ->color('gray'),
        ];
    }

    /**
     * @return array{total_cents: int, oldest_days: int|null, account_count: int}
     */
    private function receivables(): array
    {
        $outstanding = $this->outstandingByApplication();

        $accountIds = [];
        $totalCents = 0;
        $oldestDays = null;

        foreach ($outstanding as $row) {
            $totalCents += $row['balance_cents'];
            $accountIds[$row['account_id']] = true;

            if ($oldestDays === null || $row['age_days'] > $oldestDays) {
                $oldestDays = $row['age_days'];
            }
        }

        return [
            'total_cents' => $totalCents,
            'oldest_days' => $oldestDays,
            'account_count' => count($accountIds),
        ];
    }

    /**
     * @return list<array{application_id: int, account_id: int, balance_cents: int, age_days: int}>
     */
    private function outstandingByApplication(): array
    {
        $acceptedQuotes = Quote::query()
            ->where('status', 'accepted')
            ->with(['lines', 'application:id,client_account_id'])
            ->get();

        $rows = [];

        foreach ($acceptedQuotes as $quote) {
            if ($quote->application === null) {
                continue;
            }

            $quoted = $quote->lines->sum('client_price_cents');
            $paid = (int) Payment::query()
                ->whereNotNull('verified_at')
                ->where('application_id', $quote->application_id)
                ->sum('amount_cents');

            $balance = $quoted - $paid;

            if ($balance <= 0) {
                continue;
            }

            $acceptedAt = $quote->updated_at ?? $quote->created_at;

            $rows[] = [
                'application_id' => $quote->application_id,
                'account_id' => (int) $quote->application->client_account_id,
                'balance_cents' => $balance,
                'age_days' => $acceptedAt ? (int) $acceptedAt->diffInDays(Carbon::now()) : 0,
            ];
        }

        return $rows;
    }

    private function openQuoteTotal(): int
    {
        return (int) QuoteLine::query()
            ->whereHas('quote', fn ($q) => $q->where('status', 'sent'))
            ->sum('client_price_cents');
    }

    private function describeLastPayment(): string
    {
        $last = Payment::query()
            ->whereNotNull('verified_at')
            ->latest('verified_at')
            ->first();

        if ($last === null) {
            return 'None yet';
        }

        return $last->verified_at->diffForHumans();
    }

    private function describeLastPaymentAmount(): string
    {
        $last = Payment::query()
            ->whereNotNull('verified_at')
            ->latest('verified_at')
            ->first();

        if ($last === null) {
            return '';
        }

        $application = Application::query()->find($last->application_id);

        return $this->asRand($last->amount_cents).'  -  '.($application?->reference ?? 'App #'.$last->application_id);
    }

    private function asRand(int $cents): string
    {
        return 'R'.number_format($cents / 100, 2, '.', ' ');
    }

    private function formatDelta(int $current, int $previous): string
    {
        $delta = $current - $previous;
        $sign = $delta >= 0 ? '+' : '-';

        return $sign.' R'.number_format(abs($delta) / 100, 2, '.', ' ');
    }
}
