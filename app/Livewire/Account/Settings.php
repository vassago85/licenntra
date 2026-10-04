<?php

namespace App\Livewire\Account;

use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Shared account page for every signed-in user. Lets them update their
 * profile (name + email) and password. Delegates to the existing Fortify
 * action classes so validation rules stay in one place.
 */
#[Layout('layouts.portal')]
class Settings extends Component
{
    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $profileStatus = null;

    public ?string $passwordStatus = null;

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function updateProfile(UpdateUserProfileInformation $updater): void
    {
        $user = $this->currentUser();

        try {
            $updater->update($user, [
                'name' => $this->name,
                'email' => $this->email,
            ]);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return;
        }

        $this->profileStatus = 'Profile updated.';
    }

    public function updatePassword(UpdateUserPassword $updater): void
    {
        $user = $this->currentUser();

        try {
            $updater->update($user, [
                'current_password' => $this->current_password,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
            ]);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return;
        }

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->passwordStatus = 'Password updated.';
    }

    public function render(): View
    {
        $user = $this->currentUser();

        return view('livewire.account.settings', [
            'user' => $user,
            'twoFactorEnabled' => (bool) $user->two_factor_confirmed_at,
        ]);
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
