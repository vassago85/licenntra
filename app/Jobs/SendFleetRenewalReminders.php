<?php

namespace App\Jobs;

use App\Enums\ClientAccountType;
use App\Models\ClientAccount;
use App\Models\FleetVehicle;
use App\Services\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Runs daily. On the first morning of the calendar month it dispatches one
 * email per fleet listing every confirmed, non-retired vehicle whose licence
 * expires in that month. All other days it is a no-op.
 */
class SendFleetRenewalReminders implements ShouldQueue
{
    use Queueable;

    public function handle(NotificationDispatcher $notifications): void
    {
        $today = Carbon::today();

        if ($today->day !== 1) {
            return;
        }

        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        ClientAccount::query()
            ->where('type', ClientAccountType::FleetOperator->value)
            ->orderBy('id')
            ->each(function (ClientAccount $fleet) use ($notifications, $monthStart, $monthEnd): void {
                $vehicles = FleetVehicle::query()
                    ->withoutGlobalScopes()
                    ->where('client_account_id', $fleet->id)
                    ->whereNull('retired_at')
                    ->whereBetween('licence_expires_on', [$monthStart, $monthEnd])
                    ->confirmed()
                    ->orderBy('licence_expires_on')
                    ->orderBy('vehicle_register_number')
                    ->get();

                if ($vehicles->isEmpty()) {
                    return;
                }

                $notifications->fleetRenewalReminder($fleet, $vehicles, $monthStart);
            });
    }
}
