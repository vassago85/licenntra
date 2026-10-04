<?php

namespace App\Filament\Concerns;

/**
 * Audit-trail readers: super_admin, customer_admin and auditor.
 * Reviewers and finance cannot see the compliance audit log, since it
 * contains cross-dealership events outside their day-to-day scope.
 */
trait OnlyAuditors
{
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->is_active
            && $user->hasAnyRole(['super_admin', 'customer_admin', 'auditor']);
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }
}
