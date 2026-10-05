<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /**
     * Who can see that an application has invoices attached (used when
     * rendering the "Invoices" section on the application page).
     */
    public function viewAny(User $user, Application $application): bool
    {
        return $user->can('view', $application);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        $application = $invoice->application;

        return $application !== null && $user->can('view', $application);
    }

    public function download(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }

    /**
     * Only finance / customer_admin / super_admin upload invoices, and only
     * once the application has crossed PaymentVerified. Reviewers run the
     * licensing side of the workflow but do not raise invoices.
     */
    public function upload(User $user, Application $application): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->can('view', $application)) {
            return false;
        }

        if (! $application->stage->canCarryInvoice()) {
            return false;
        }

        return $user->hasAnyRole(['finance', 'owner']);
    }

    public function markPaid(User $user, Invoice $invoice): bool
    {
        return $this->financeAct($user, $invoice);
    }

    public function markUnpaid(User $user, Invoice $invoice): bool
    {
        return $this->financeAct($user, $invoice);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->financeAct($user, $invoice);
    }

    private function financeAct(User $user, Invoice $invoice): bool
    {
        $application = $invoice->application;

        if ($application === null || ! $user->is_active) {
            return false;
        }

        if (! $user->can('view', $application)) {
            return false;
        }

        return $user->hasAnyRole(['finance', 'owner']);
    }
}
