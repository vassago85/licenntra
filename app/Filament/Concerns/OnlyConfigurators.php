<?php

namespace App\Filament\Concerns;

/**
 * Restrict a Filament resource or page to users who may configure the
 * licensing company (super_admin and customer_admin). Reviewers, finance
 * and auditors can still access operational pages but must not see this
 * one in navigation or reach it by URL.
 */
trait OnlyConfigurators
{
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'canConfigure') && $user->canConfigure();
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
