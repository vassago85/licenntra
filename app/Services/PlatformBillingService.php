<?php

namespace App\Services;

use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * Computes the Charsley Digital -> licensing company owner platform bill:
 * number of applications that first entered the Completed stage inside a
 * given calendar month, multiplied by the per-transaction fee the
 * developer configured.
 *
 * Cancelled and Archived applications are deliberately excluded - only
 * work that actually reached Completed counts as a billable transaction.
 * The metering timestamp is Application::completed_at, which TransitionApplication
 * stamps once the first time an application reaches Completed and never
 * updates again.
 */
class PlatformBillingService
{
    /**
     * @return array{
     *     month: Carbon,
     *     label: string,
     *     count: int,
     *     fee_per_transaction_cents: int,
     *     total_cents: int,
     * }
     */
    public function monthlyUsage(?Carbon $month = null): array
    {
        $month = ($month ?? now())->copy()->startOfMonth();
        $end = (clone $month)->endOfMonth();

        $count = Application::query()
            ->whereIn('stage', [ApplicationStage::Completed, ApplicationStage::Archived])
            ->whereBetween('completed_at', [$month, $end])
            ->count();

        $fee = (int) SystemSetting::current()->platform_fee_per_transaction_cents;

        return [
            'month' => $month,
            'label' => $month->format('F Y'),
            'count' => $count,
            'fee_per_transaction_cents' => $fee,
            'total_cents' => $count * $fee,
        ];
    }

    /**
     * Recent months for context on the billing dashboard.
     *
     * @return array<int, array{month: Carbon, label: string, count: int, fee_per_transaction_cents: int, total_cents: int}>
     */
    public function recentMonths(int $months = 6): array
    {
        $rows = [];
        $cursor = now()->copy()->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $rows[] = $this->monthlyUsage($cursor->copy());
            $cursor->subMonthNoOverflow();
        }

        return $rows;
    }
}
