<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ScopesToClientAccount
{
    public static function bootScopesToClientAccount(): void
    {
        static::addGlobalScope('client_account', function (Builder $query): void {
            $user = auth()->user();

            if ($user?->client_account_id) {
                $query->where($query->getModel()->getTable().'.client_account_id', $user->client_account_id);
            }
        });
    }
}
