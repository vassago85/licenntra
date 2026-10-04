<?php

namespace App\Filament\Concerns;

/**
 * Dashboard widgets that show cash position. Finance can see them, as can
 * super_admin and customer_admin. Reviewers and auditors should not see
 * receivables and transaction widgets on their dashboard.
 */
trait OnlyFinanceOrAdmin
{
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->is_active
            && $user->hasAnyRole(['finance', 'super_admin', 'customer_admin']);
    }
}
