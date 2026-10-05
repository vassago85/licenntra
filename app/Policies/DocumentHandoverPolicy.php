<?php

namespace App\Policies;

use App\Models\DocumentHandover;
use App\Models\User;

class DocumentHandoverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isClient() || $user->isLicensingStaff());
    }

    public function view(User $user, DocumentHandover $handover): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isClient()) {
            return $user->client_account_id === $handover->client_account_id;
        }

        return $user->isLicensingStaff();
    }

    public function create(User $user): bool
    {
        return $user->is_active && ($user->isClient() || $this->isOperations($user));
    }

    public function update(User $user, DocumentHandover $handover): bool
    {
        return $this->view($user, $handover)
            && $handover->isPending()
            && ($user->isClient() || $this->isOperations($user));
    }

    public function confirm(User $user, DocumentHandover $handover): bool
    {
        return $this->update($user, $handover);
    }

    public function delete(User $user, DocumentHandover $handover): bool
    {
        return $this->update($user, $handover);
    }

    public function print(User $user, DocumentHandover $handover): bool
    {
        return $this->view($user, $handover);
    }

    public function uploadSigned(User $user, DocumentHandover $handover): bool
    {
        return $this->view($user, $handover) && ($user->isClient() || $this->isOperations($user));
    }

    private function isOperations(User $user): bool
    {
        return $user->hasAnyRole(['reviewer', 'owner']);
    }
}
