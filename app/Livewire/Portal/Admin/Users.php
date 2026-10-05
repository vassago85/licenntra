<?php

namespace App\Livewire\Portal\Admin;

use App\Actions\OffboardStaffMember;
use App\Enums\OffboardReason;
use App\Exceptions\OffboardingNotAllowed;
use App\Livewire\Portal\Admin\Concerns\RequiresConfigurator;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Owner-facing user management: licensing staff and dealer / fleet
 * logins. Developer (Charsley Digital) accounts are not listed or
 * assignable here.
 */
#[Layout('layouts.portal')]
class Users extends Component
{
    use RequiresConfigurator;
    use WithPagination;

    /** @var array<string, string> */
    public const ROLE_LABELS = [
        'owner' => 'Owner',
        'reviewer' => 'Operations',
        'finance' => 'Finance',
        'customer_admin' => 'Dealer / fleet admin',
        'customer_user' => 'Dealer / fleet user',
    ];

    private const CUSTOMER_ROLES = ['customer_admin', 'customer_user'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $roleFilter = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = 'reviewer';

    public string $clientAccountId = '';

    public bool $isActive = true;

    public ?int $offboardingId = null;

    public string $offboardReason = '';

    public string $offboardNote = '';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'roleFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $userId): void
    {
        $user = $this->findManagedUser($userId);
        abort_if($user->isOffboarded(), 403);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = (string) ($user->getRoleNames()->first() ?? 'reviewer');
        $this->clientAccountId = $user->client_account_id ? (string) $user->client_account_id : '';
        $this->isActive = (bool) $user->is_active;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->clearMessages();
        $isCustomer = in_array($this->role, self::CUSTOMER_ROLES, true);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'password' => [$this->editingId === null ? 'required' : 'nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in(array_keys(self::ROLE_LABELS))],
            'clientAccountId' => [$isCustomer ? 'required' : 'nullable', Rule::exists('client_accounts', 'id')],
            'isActive' => ['boolean'],
        ], [
            'clientAccountId.required' => 'Pick the dealer or fleet this login belongs to.',
        ]);

        $user = $this->editingId === null ? new User : $this->findManagedUser($this->editingId);

        if ($user->exists && $this->wouldStrandOwners($user, $data['role'], (bool) $data['isActive'])) {
            $this->addError('role', 'At least one active owner must remain.');

            return;
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'client_account_id' => $isCustomer ? (int) $data['clientAccountId'] : null,
            'is_active' => (bool) $data['isActive'],
        ]);

        if (filled($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();
        $user->syncRoles([$data['role']]);

        $this->statusMessage = $this->editingId === null
            ? $user->name.' created. Share the initial password securely.'
            : $user->name.' updated.';

        $this->resetForm();
    }

    public function deactivate(int $userId): void
    {
        $this->clearMessages();
        $user = $this->findManagedUser($userId);

        if ($user->id === $this->configurator()->id) {
            $this->errorMessage = 'You cannot deactivate your own account.';

            return;
        }

        if ($this->wouldStrandOwners($user, null, false)) {
            $this->errorMessage = 'At least one active owner must remain.';

            return;
        }

        $user->update(['is_active' => false]);
        $this->statusMessage = $user->name.' deactivated. They keep every audit attribution but cannot sign in.';
    }

    public function activate(int $userId): void
    {
        $this->clearMessages();
        $user = $this->findManagedUser($userId);
        abort_if($user->isOffboarded(), 403);

        $user->update(['is_active' => true]);
        $this->statusMessage = $user->name.' activated.';
    }

    public function startOffboarding(int $userId): void
    {
        $this->clearMessages();
        $user = $this->findManagedUser($userId);
        abort_unless($user->isLicensingStaff() && ! $user->isOffboarded(), 403);

        $this->offboardingId = $user->id;
        $this->offboardReason = '';
        $this->offboardNote = '';
        $this->resetErrorBag();
    }

    public function cancelOffboarding(): void
    {
        $this->offboardingId = null;
        $this->offboardReason = '';
        $this->offboardNote = '';
        $this->resetErrorBag();
    }

    public function confirmOffboarding(OffboardStaffMember $action): void
    {
        if ($this->offboardingId === null) {
            return;
        }

        $this->clearMessages();

        $data = $this->validate([
            'offboardReason' => ['required', Rule::in(array_keys(OffboardReason::options()))],
            'offboardNote' => ['nullable', 'string', 'max:2000'],
        ], [
            'offboardReason.required' => 'Choose a reason.',
        ]);

        $user = $this->findManagedUser($this->offboardingId);

        try {
            $action->handle($user, OffboardReason::from($data['offboardReason']), $data['offboardNote'] ?: null, $this->configurator());
        } catch (OffboardingNotAllowed $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $this->statusMessage = $user->name.' offboarded. Record retained until '.$user->fresh()->retentionEndsAt()?->format('d M Y').'.';
        $this->cancelOffboarding();
    }

    public function render(): View
    {
        return view('livewire.portal.admin.users', [
            'users' => $this->query()->paginate(25),
            'accounts' => ClientAccount::query()->orderBy('name')->pluck('name', 'id'),
            'roleLabels' => self::ROLE_LABELS,
            'offboardReasons' => OffboardReason::options(),
            'currentUserId' => $this->configurator()->id,
        ]);
    }

    private function query(): Builder
    {
        return User::query()
            ->with(['roles', 'clientAccount'])
            ->whereDoesntHave('roles', fn (Builder $query) => $query->where('name', 'developer'))
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhereHas('clientAccount', fn (Builder $account) => $account->where('name', 'like', $term)));
            })
            ->when($this->roleFilter !== '', fn (Builder $query) => $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', $this->roleFilter)))
            ->when($this->statusFilter !== '', fn (Builder $query) => match ($this->statusFilter) {
                'active' => $query->where('is_active', true)->whereNull('offboarded_at'),
                'deactivated' => $query->where('is_active', false)->whereNull('offboarded_at'),
                'offboarded' => $query->whereNotNull('offboarded_at')->whereNull('anonymised_at'),
                'anonymised' => $query->whereNotNull('anonymised_at'),
                default => $query,
            })
            ->orderByRaw('client_account_id is not null')
            ->orderBy('name');
    }

    private function findManagedUser(int $userId): User
    {
        $user = User::query()
            ->whereKey($userId)
            ->whereDoesntHave('roles', fn (Builder $query) => $query->where('name', 'developer'))
            ->first();

        abort_unless($user instanceof User, 404);

        return $user;
    }

    /**
     * True when the change would leave the platform without an active owner.
     */
    private function wouldStrandOwners(User $user, ?string $newRole, bool $willBeActive): bool
    {
        if (! $user->hasRole('owner')) {
            return false;
        }

        $staysOwner = ($newRole ?? 'owner') === 'owner' && $willBeActive;

        if ($staysOwner) {
            return false;
        }

        return ! User::query()
            ->whereKeyNot($user->id)
            ->where('is_active', true)
            ->whereNull('offboarded_at')
            ->whereHas('roles', fn (Builder $query) => $query->where('name', 'owner'))
            ->exists();
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->role = 'reviewer';
        $this->clientAccountId = '';
        $this->isActive = true;
        $this->resetErrorBag();
    }

    private function clearMessages(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
    }
}
