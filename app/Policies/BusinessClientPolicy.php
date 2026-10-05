<?php

namespace App\Policies;

use App\Models\BusinessClient;
use App\Models\User;

class BusinessClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isClient() || $user->isLicensingStaff());
    }

    public function view(User $user, BusinessClient $businessClient): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isClient()) {
            // Own-account records always, plus shared title holders /
            // finance houses that any dealership can see.
            return $user->client_account_id === $businessClient->client_account_id
                || $businessClient->isShared();
        }

        return $user->isLicensingStaff();
    }

    /**
     * Only dealership managers (client_admin) and licensing operations
     * staff (reviewer / customer_admin / super_admin) may add a business
     * client. A plain client_user (dealer sales user) can reference
     * existing records on an application form but cannot spawn new
     * owner/finance-house rows that would leak across the dealership.
     * Finance and auditor are read-only roles.
     */
    public function create(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin'])
            || ($user->isClient() && $user->hasRole('client_admin'));
    }

    public function update(User $user, BusinessClient $businessClient): bool
    {
        if (! $this->view($user, $businessClient)) {
            return false;
        }

        return $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin'])
            || ($user->isClient() && $user->hasRole('client_admin'));
    }
}
