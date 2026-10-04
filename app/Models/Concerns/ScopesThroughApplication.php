<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ScopesThroughApplication
{
    public static function bootScopesThroughApplication(): void
    {
        static::addGlobalScope('client_account', function (Builder $query): void {
            $user = auth()->user();

            if ($user?->client_account_id) {
                $query->whereHas('application', function (Builder $application) use ($user): void {
                    $application->where('client_account_id', $user->client_account_id);
                });
            }
        });
    }
}
