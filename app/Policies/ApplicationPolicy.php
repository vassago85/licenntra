<?php

namespace App\Policies;

use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isClient() || $user->isLicensingStaff());
    }

    public function view(User $user, Application $application): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isClient()) {
            return $user->client_account_id === $application->client_account_id;
        }

        return $user->isLicensingStaff();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isClient();
    }

    public function update(User $user, Application $application): bool
    {
        if (! $this->view($user, $application)) {
            return false;
        }

        if ($user->isClient()) {
            return in_array($application->stage, [ApplicationStage::Draft, ApplicationStage::ChangesRequested], true);
        }

        return $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']);
    }

    public function review(User $user, Application $application): bool
    {
        return $user->is_active
            && $this->view($user, $application)
            && $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']);
    }

    public function acceptQuote(User $user, Application $application): bool
    {
        return $user->is_active
            && $user->isClient()
            && $user->client_account_id === $application->client_account_id
            && $user->canAcceptQuotes();
    }

    public function verifyPayment(User $user, Application $application): bool
    {
        return $user->is_active
            && $this->view($user, $application)
            && $user->hasAnyRole(['finance', 'customer_admin', 'super_admin']);
    }
}
