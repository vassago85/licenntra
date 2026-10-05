<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\DeliverableDocument;
use App\Models\User;

class DeliverableDocumentPolicy
{
    /**
     * Who can see that an application has a deliverable attached (used
     * when rendering the "Returned documents" section).
     */
    public function viewAny(User $user, Application $application): bool
    {
        return $user->can('view', $application);
    }

    public function view(User $user, DeliverableDocument $deliverable): bool
    {
        $application = $deliverable->application;

        return $application !== null && $user->can('view', $application);
    }

    public function download(User $user, DeliverableDocument $deliverable): bool
    {
        return $this->view($user, $deliverable);
    }

    /**
     * Only licensing-company reviewers / customer admins / super admins
     * upload deliverables — these are documents returned by the
     * licensing department and brought back in-house.
     */
    public function upload(User $user, Application $application): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->can('view', $application)) {
            return false;
        }

        return $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin']);
    }

    public function delete(User $user, DeliverableDocument $deliverable): bool
    {
        $application = $deliverable->application;

        if ($application === null) {
            return false;
        }

        return $this->upload($user, $application);
    }

    /**
     * Who can email the application's deliverables straight to the
     * vehicle owner. The dealer who owns the application is the main
     * sender - they talk to the end customer. Licensing-company staff
     * can also send on the dealer's behalf when needed.
     */
    public function sendToCustomer(User $user, Application $application): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->can('view', $application)) {
            return false;
        }

        return $user->hasAnyRole([
            'client_user', 'client_admin', 'reviewer', 'customer_admin', 'super_admin',
        ]);
    }
}
