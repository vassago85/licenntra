<?php

namespace App\Filament\Concerns;

/**
 * Restrict a Filament page to the licensing-company owner (super_admin)
 * and Charsley Digital platform staff (developer). Used by the platform
 * billing dashboard, which is explicitly off-limits to customer_admin,
 * finance, reviewers and auditors - this is private commercial info
 * between the owner and the developer.
 */
trait OnlyOwnerOrDeveloper
{
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->is_active
            && method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['super_admin', 'developer']);
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
