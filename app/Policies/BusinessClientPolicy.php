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

    public function create(User $user): bool
    {
        return $user->is_active && $user->isClient();
    }

    public function update(User $user, BusinessClient $businessClient): bool
    {
        return $this->view($user, $businessClient) && ($user->isClient() || $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']));
    }
}
