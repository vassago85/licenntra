<?php

namespace App\Livewire\Portal\Admin\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Restricts a portal admin component to users who may configure the
 * licensing company (the owner). Runs on every Livewire request, so
 * actions called directly after mount are guarded too.
 */
trait RequiresConfigurator
{
    public function bootRequiresConfigurator(): void
    {
        abort_unless($this->configurator()->canConfigure(), 403);
    }

    protected function configurator(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
