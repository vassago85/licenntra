<?php

namespace App\Livewire\Portal;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Team management for a dealership super admin (role: client_admin).
 *
 * Scoped strictly to the signed-in admin's own client account - they can
 * create additional client_user or client_admin accounts, deactivate /
 * reactivate team members, and reset a team member's password. Licensing
 * company staff use the Filament /admin/users resource instead.
 */
#[Layout('layouts.portal')]
class TeamIndex extends Component
{
    public string $name = '';

    public string $email = '';

    public string $role = 'client_user';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $showCreate = false;

    public ?int $resettingUserId = null;

    public string $resetPassword = '';

    public string $resetPasswordConfirmation = '';

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $user = $this->currentUser();
        abort_unless($user->hasRole('client_admin'), 403);
        abort_unless($user->client_account_id !== null, 403);
    }

    public function openCreate(): void
    {
        $this->reset(['name', 'email', 'role', 'password', 'password_confirmation']);
        $this->role = 'client_user';
        $this->resetErrorBag();
        $this->showCreate = true;
    }

    public function cancelCreate(): void
    {
        $this->showCreate = false;
        $this->reset(['name', 'email', 'role', 'password', 'password_confirmation']);
        $this->resetErrorBag();
    }

    public function createMember(): void
    {
        $admin = $this->currentUser();
        $account = $admin->clientAccount;
        abort_unless($account instanceof ClientAccount, 403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'in:client_user,client_admin'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $member = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'client_account_id' => $account->id,
            'is_active' => true,
        ]);

        $member->syncRoles([$data['role']]);

        $this->showCreate = false;
        $this->reset(['name', 'email', 'role', 'password', 'password_confirmation']);
        $this->statusMessage = $member->name.' added. Share the initial password securely so they can sign in and change it.';
    }

    public function deactivate(int $userId): void
    {
        $member = $this->findMember($userId);

        if ($member->id === $this->currentUser()->id) {
            $this->statusMessage = 'You cannot deactivate your own account.';

            return;
        }

        if ($this->wouldStrandAdmins($member)) {
            $this->statusMessage = 'At least one active admin must remain for this dealership.';

            return;
        }

        $member->update(['is_active' => false]);
        $this->statusMessage = $member->name.' deactivated.';
    }

    public function activate(int $userId): void
    {
        $member = $this->findMember($userId);
        $member->update(['is_active' => true]);
        $this->statusMessage = $member->name.' activated.';
    }

    public function startPasswordReset(int $userId): void
    {
        $this->findMember($userId);
        $this->resettingUserId = $userId;
        $this->resetPassword = '';
        $this->resetPasswordConfirmation = '';
        $this->resetErrorBag();
    }

    public function cancelPasswordReset(): void
    {
        $this->resettingUserId = null;
        $this->resetPassword = '';
        $this->resetPasswordConfirmation = '';
        $this->resetErrorBag();
    }

    public function completePasswordReset(): void
    {
        if ($this->resettingUserId === null) {
            return;
        }

        $member = $this->findMember($this->resettingUserId);

        $this->validate([
            'resetPassword' => ['required', 'string', 'min:8'],
            'resetPasswordConfirmation' => ['required', 'same:resetPassword'],
        ], [
            'resetPasswordConfirmation.same' => 'Passwords must match.',
        ]);

        $member->forceFill(['password' => Hash::make($this->resetPassword)])->save();

        $this->resettingUserId = null;
        $this->resetPassword = '';
        $this->resetPasswordConfirmation = '';
        $this->statusMessage = $member->name."'s password was reset. Share the new password securely.";
    }

    public function render(): View
    {
        $admin = $this->currentUser();
        $account = $admin->clientAccount;

        $members = User::query()
            ->where('client_account_id', $admin->client_account_id)
            ->with('roles')
            ->orderBy('name')
            ->get();

        return view('livewire.portal.team-index', [
            'members' => $members,
            'account' => $account,
            'canReset' => $this->resettingUserId,
        ]);
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function findMember(int $userId): User
    {
        $admin = $this->currentUser();
        $member = User::query()
            ->where('id', $userId)
            ->where('client_account_id', $admin->client_account_id)
            ->first();

        abort_unless($member instanceof User, 404);

        return $member;
    }

    private function wouldStrandAdmins(User $candidate): bool
    {
        if (! $candidate->hasRole('client_admin')) {
            return false;
        }

        $remaining = User::query()
            ->where('client_account_id', $candidate->client_account_id)
            ->where('id', '!=', $candidate->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'client_admin'))
            ->count();

        return $remaining === 0;
    }
}
