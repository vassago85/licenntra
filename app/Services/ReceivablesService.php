<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Money figures for the staff overview. "Outstanding" means accepted
 * quotes whose application has not yet been paid in full by verified
 * payments; age runs from the quote acceptance date.
 */
class ReceivablesService
{
    /**
     * @return array{outstanding_cents: int, oldest_days: ?int, account_count: int, verified_this_month_cents: int, verified_last_month_cents: int, open_quote_count: int, open_quote_cents: int, last_payment: ?Payment}
     */
    public function summary(): array
    {
        $outstanding = $this->outstandingByApplication();

        return [
            'outstanding_cents' => (int) $outstanding->sum('balance_cents'),
            'oldest_days' => $outstanding->max('age_days'),
            'account_count' => $outstanding->pluck('account_id')->unique()->count(),
            'verified_this_month_cents' => (int) Payment::query()
                ->whereNotNull('verified_at')
                ->where('verified_at', '>=', Carbon::now()->startOfMonth())
                ->sum('amount_cents'),
            'verified_last_month_cents' => (int) Payment::query()
                ->whereNotNull('verified_at')
                ->whereBetween('verified_at', [Carbon::now()->subMonthNoOverflow()->startOfMonth(), Carbon::now()->subMonthNoOverflow()->endOfMonth()])
                ->sum('amount_cents'),
            'open_quote_count' => Quote::query()->where('status', 'sent')->count(),
            'open_quote_cents' => (int) QuoteLine::query()
                ->whereHas('quote', fn ($query) => $query->where('status', 'sent'))
                ->sum('client_price_cents'),
            'last_payment' => Payment::query()
                ->whereNotNull('verified_at')
                ->with('application:id,reference')
                ->latest('verified_at')
                ->first(),
        ];
    }

    /**
     * Accounts that owe money or paid something in the last 90 days, largest
     * balance first.
     *
     * @return Collection<int, array{account: ClientAccount, outstanding_cents: int, oldest_days: ?int, open_quotes: int, verified_90d_cents: int}>
     */
    public function customerBalances(int $limit = 10): Collection
    {
        $balances = [];
        $blank = ['outstanding_cents' => 0, 'oldest_days' => null, 'open_quotes' => 0, 'verified_90d_cents' => 0];

        foreach ($this->outstandingByApplication() as $row) {
            $balances[$row['account_id']] ??= $blank;
            $balances[$row['account_id']]['outstanding_cents'] += $row['balance_cents'];
            $balances[$row['account_id']]['oldest_days'] = max($balances[$row['account_id']]['oldest_days'] ?? 0, $row['age_days']);
        }

        $openQuotes = Quote::query()
            ->where('quotes.status', 'sent')
            ->join('applications', 'applications.id', '=', 'quotes.application_id')
            ->selectRaw('applications.client_account_id as account_id, count(*) as total')
            ->groupBy('applications.client_account_id')
            ->pluck('total', 'account_id');

        foreach ($openQuotes as $accountId => $total) {
            $balances[(int) $accountId] ??= $blank;
            $balances[(int) $accountId]['open_quotes'] = (int) $total;
        }

        foreach ($this->verifiedByAccountSince(Carbon::now()->subDays(90)) as $accountId => $cents) {
            $balances[$accountId] ??= $blank;
            $balances[$accountId]['verified_90d_cents'] = $cents;
        }

        $balances = array_filter($balances, fn (array $row): bool => $row['outstanding_cents'] > 0 || $row['verified_90d_cents'] > 0);
        $accounts = ClientAccount::query()->whereIn('id', array_keys($balances))->get()->keyBy('id');

        return collect($balances)
            ->filter(fn (array $row, int $accountId): bool => $accounts->has($accountId))
            ->map(fn (array $row, int $accountId): array => ['account' => $accounts[$accountId]] + $row)
            ->sortByDesc('outstanding_cents')
            ->take($limit)
            ->values();
    }

    /**
     * Top accounts by verified revenue over the window, with throughput.
     *
     * @return Collection<int, array{account: ClientAccount, applications: int, revenue_cents: int, average_cents: ?int}>
     */
    public function topCustomers(int $days = 90, int $limit = 10): Collection
    {
        $since = Carbon::now()->subDays($days);
        $revenue = $this->verifiedByAccountSince($since);

        if ($revenue === []) {
            return collect();
        }

        $applications = Application::query()
            ->where('created_at', '>=', $since)
            ->whereIn('client_account_id', array_keys($revenue))
            ->selectRaw('client_account_id, count(*) as total')
            ->groupBy('client_account_id')
            ->pluck('total', 'client_account_id');

        $accounts = ClientAccount::query()->whereIn('id', array_keys($revenue))->get()->keyBy('id');

        return collect($revenue)
            ->filter(fn (int $cents, int $accountId): bool => $accounts->has($accountId))
            ->map(function (int $cents, int $accountId) use ($applications, $accounts): array {
                $count = (int) ($applications[$accountId] ?? 0);

                return [
                    'account' => $accounts[$accountId],
                    'applications' => $count,
                    'revenue_cents' => $cents,
                    'average_cents' => $count > 0 ? intdiv($cents, $count) : null,
                ];
            })
            ->sortByDesc('revenue_cents')
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, array{application_id: int, account_id: int, balance_cents: int, age_days: int}>
     */
    private function outstandingByApplication(): Collection
    {
        $quotes = Quote::query()
            ->where('status', 'accepted')
            ->with(['lines:id,quote_id,client_price_cents', 'application:id,client_account_id'])
            ->get()
            ->filter(fn (Quote $quote): bool => $quote->application !== null);

        $paidByApplication = Payment::query()
            ->whereNotNull('verified_at')
            ->whereIn('application_id', $quotes->pluck('application_id')->unique())
            ->selectRaw('application_id, sum(amount_cents) as total')
            ->groupBy('application_id')
            ->pluck('total', 'application_id');

        return $quotes
            ->map(function (Quote $quote) use ($paidByApplication): array {
                $acceptedAt = $quote->updated_at ?? $quote->created_at;

                return [
                    'application_id' => (int) $quote->application_id,
                    'account_id' => (int) $quote->application->client_account_id,
                    'balance_cents' => (int) $quote->lines->sum('client_price_cents') - (int) ($paidByApplication[$quote->application_id] ?? 0),
                    'age_days' => $acceptedAt ? (int) $acceptedAt->diffInDays(Carbon::now()) : 0,
                ];
            })
            ->filter(fn (array $row): bool => $row['balance_cents'] > 0)
            ->values();
    }

    /**
     * @return array<int, int> account id => verified cents
     */
    private function verifiedByAccountSince(Carbon $since): array
    {
        return Payment::query()
            ->whereNotNull('payments.verified_at')
            ->where('payments.verified_at', '>=', $since)
            ->join('applications', 'applications.id', '=', 'payments.application_id')
            ->selectRaw('applications.client_account_id as account_id, sum(payments.amount_cents) as total')
            ->groupBy('applications.client_account_id')
            ->pluck('total', 'account_id')
            ->mapWithKeys(fn ($total, $accountId): array => [(int) $accountId => (int) $total])
            ->all();
    }
}
