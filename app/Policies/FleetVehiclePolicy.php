<?php

namespace App\Policies;

use App\Enums\ClientAccountType;
use App\Models\ClientAccount;
use App\Models\FleetVehicle;
use App\Models\User;

class FleetVehiclePolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isClient()) {
            return $user->clientAccount?->hasType(ClientAccountType::FleetOperator) ?? false;
        }

        return $user->isLicensingStaff();
    }

    public function view(User $user, FleetVehicle $vehicle): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isClient()) {
            return ($user->clientAccount?->hasType(ClientAccountType::FleetOperator) ?? false)
                && $user->client_account_id === $vehicle->client_account_id;
        }

        return $user->isLicensingStaff();
    }

    public function retire(User $user, FleetVehicle $vehicle): bool
    {
        return $this->view($user, $vehicle)
            && ($user->isClient() || $user->canConfigure());
    }

    public function uploadFor(User $user, ClientAccount $fleet): bool
    {
        return $user->is_active
            && $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin'])
            && $fleet->hasType(ClientAccountType::FleetOperator);
    }

    public function reviewAny(User $user): bool
    {
        return $user->is_active
            && $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']);
    }

    public function download(User $user, FleetVehicle $vehicle): bool
    {
        return $this->view($user, $vehicle);
    }
}
