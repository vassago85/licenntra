<?php

namespace App\Services;

use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Money figures for the staff overview.
 *
 * "Outstanding" is everything a client owes: fees billed onto a statement
 * or for invoicing that no paid invoice has settled yet, applications
 * waiting for an up-front payment, and accepted quotes not yet billed.
 *
 * "Received" is money that actually landed: verified cash payments, plus
 * billed entries on the day finance marked their invoice paid.
 */
class ReceivablesService
{
    /**
     * @return array{outstanding_cents: int, oldest_days: ?int, account_count: int, received_this_month_cents: int, received_last_month_cents: int, open_quote_count: int, open_quote_cents: int, last_payment: ?Payment, last_payment_at: ?Carbon}
     */
    public function summary(): array
    {
        $outstanding = $this->outstandingByApplication();
        $lastMonth = Carbon::now()->subMonthNoOverflow();
        [$lastPayment, $lastPaymentAt] = $this->lastReceipt();

        return [
            'outstanding_cents' => (int) $outstanding->sum('balance_cents'),
            'oldest_days' => $outstanding->max('age_days'),
            'account_count' => $outstanding->pluck('account_id')->unique()->count(),
            'received_this_month_cents' => array_sum($this->receivedByAccountBetween(Carbon::now()->startOfMonth(), Carbon::now())),
            'received_last_month_cents' => array_sum($this->receivedByAccountBetween($lastMonth->copy()->startOfMonth(), $lastMonth->copy()->endOfMonth())),
            'open_quote_count' => Quote::query()->where('status', 'sent')->count(),
            'open_quote_cents' => (int) QuoteLine::query()
                ->whereHas('quote', fn ($query) => $query->where('status', 'sent'))
                ->sum('client_price_cents'),
            'last_payment' => $lastPayment,
            'last_payment_at' => $lastPaymentAt,
        ];
    }

    /**
     * Accounts that owe money or paid something in the last 90 days, largest
     * balance first.
     *
     * @return Collection<int, array{account: ClientAccount, outstanding_cents: int, oldest_days: ?int, open_quotes: int, received_90d_cents: int}>
     */
    public function customerBalances(int $limit = 10): Collection
    {
        $balances = [];
        $blank = ['outstanding_cents' => 0, 'oldest_days' => null, 'open_quotes' => 0, 'received_90d_cents' => 0];

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

        foreach ($this->receivedByAccountBetween(Carbon::now()->subDays(90), Carbon::now()) as $accountId => $cents) {
            $balances[$accountId] ??= $blank;
            $balances[$accountId]['received_90d_cents'] = $cents;
        }

        $balances = array_filter($balances, fn (array $row): bool => $row['outstanding_cents'] > 0 || $row['received_90d_cents'] > 0);
        $accounts = ClientAccount::query()->whereIn('id', array_keys($balances))->get()->keyBy('id');

        return collect($balances)
            ->filter(fn (array $row, int $accountId): bool => $accounts->has($accountId))
            ->map(fn (array $row, int $accountId): array => ['account' => $accounts[$accountId]] + $row)
            ->sortByDesc('outstanding_cents')
            ->take($limit)
            ->values();
    }

    /**
     * Top accounts by received revenue over the window, with throughput.
     *
     * @return Collection<int, array{account: ClientAccount, applications: int, revenue_cents: int, average_cents: ?int}>
     */
    public function topCustomers(int $days = 90, int $limit = 10): Collection
    {
        $since = Carbon::now()->subDays($days);
        $revenue = array_filter($this->receivedByAccountBetween($since, Carbon::now()), fn (int $cents): bool => $cents > 0);

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
        $now = Carbon::now();
        $ageFrom = fn (?Carbon $date): int => $date ? (int) $date->diffInDays($now) : 0;

        $billed = Payment::query()
            ->outstandingOnStatement()
            ->with('application:id,client_account_id')
            ->get()
            ->filter(fn (Payment $payment): bool => $payment->application !== null)
            ->map(fn (Payment $payment): array => [
                'application_id' => (int) $payment->application_id,
                'account_id' => (int) $payment->application->client_account_id,
                'balance_cents' => (int) $payment->amount_cents,
                'age_days' => $ageFrom($payment->verified_at ?? $payment->created_at),
            ]);

        $awaitingCash = Application::query()
            ->where('stage', ApplicationStage::PaymentPending)
            ->whereDoesntHave('payments', fn ($query) => $query->whereNotNull('verified_at'))
            ->get(['id', 'client_account_id', 'fee_snapshot', 'updated_at'])
            ->map(fn (Application $application): array => [
                'application_id' => (int) $application->id,
                'account_id' => (int) $application->client_account_id,
                'balance_cents' => (int) ($application->fee_snapshot['total_cents'] ?? 0),
                'age_days' => $ageFrom($application->updated_at),
            ]);

        $acceptedNotBilled = Quote::query()
            ->where('status', 'accepted')
            ->whereHas('application', fn ($query) => $query->where('stage', ApplicationStage::QuoteAccepted))
            ->with(['lines:id,quote_id,client_price_cents', 'application:id,client_account_id'])
            ->get()
            ->map(fn (Quote $quote): array => [
                'application_id' => (int) $quote->application_id,
                'account_id' => (int) $quote->application->client_account_id,
                'balance_cents' => (int) $quote->lines->sum('client_price_cents'),
                'age_days' => $ageFrom($quote->updated_at ?? $quote->created_at),
            ]);

        return $billed
            ->concat($awaitingCash)
            ->concat($acceptedNotBilled)
            ->filter(fn (array $row): bool => $row['balance_cents'] > 0)
            ->values();
    }

    /**
     * @return array<int, int> account id => received cents
     */
    private function receivedByAccountBetween(Carbon $from, Carbon $until): array
    {
        $cash = Payment::query()
            ->where('payments.on_account', false)
            ->whereBetween('payments.verified_at', [$from, $until]);

        $settled = Payment::query()
            ->where('payments.on_account', true)
            ->whereBetween('payments.statement_settled_at', [$from, $until]);

        $received = [];

        foreach ([$cash, $settled] as $query) {
            $totals = $query
                ->join('applications', 'applications.id', '=', 'payments.application_id')
                ->selectRaw('applications.client_account_id as account_id, sum(payments.amount_cents) as total')
                ->groupBy('applications.client_account_id')
                ->pluck('total', 'account_id');

            foreach ($totals as $accountId => $total) {
                $received[(int) $accountId] = ($received[(int) $accountId] ?? 0) + (int) $total;
            }
        }

        return $received;
    }

    /**
     * @return array{0: ?Payment, 1: ?Carbon}
     */
    private function lastReceipt(): array
    {
        $cash = Payment::query()
            ->where('on_account', false)
            ->whereNotNull('verified_at')
            ->with('application:id,reference')
            ->latest('verified_at')
            ->first();

        $settled = Payment::query()
            ->where('on_account', true)
            ->whereNotNull('statement_settled_at')
            ->with('application:id,reference')
            ->latest('statement_settled_at')
            ->first();

        if ($settled !== null && ($cash === null || $settled->statement_settled_at->greaterThan($cash->verified_at))) {
            return [$settled, $settled->statement_settled_at];
        }

        return [$cash, $cash?->verified_at];
    }
}
