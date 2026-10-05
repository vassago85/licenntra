<?php

namespace App\Livewire\Portal\Admin;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Portal replacement for the former Filament "Audit log" resource.
 *
 * Read-only, cross-dealership compliance log. Visible to super_admin,
 * customer_admin and auditor only - reviewers and finance work from
 * operational queues and should not see other dealerships' events.
 */
#[Layout('layouts.portal')]
class AuditLog extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'action', except: '')]
    public string $actionFilter = '';

    #[Url(as: 'role', except: '')]
    public string $actorRoleFilter = '';

    /**
     * When off, we hide rows written by the system (jobs, schedulers,
     * cascading transitions). On by default - operators need to see them
     * for a complete picture, but can collapse the noise.
     */
    #[Url(as: 'system')]
    public bool $includeSystem = true;

    public function mount(): void
    {
        $user = $this->currentUser();
        abort_unless($user->hasAnyRole(['super_admin', 'customer_admin', 'auditor']), 403);
    }

    /**
     * Reset to page 1 whenever a filter changes so the user does not
     * end up on an empty page of a narrower result set.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'actionFilter', 'actorRoleFilter', 'includeSystem'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->actionFilter = '';
        $this->actorRoleFilter = '';
        $this->includeSystem = true;
        $this->resetPage();
    }

    public function render(): View
    {
        $events = $this->query()->paginate(25);

        return view('livewire.portal.admin.audit-log', [
            'events' => $events,
            'actionOptions' => $this->actionOptions(),
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    private function query(): Builder
    {
        $query = AuditEvent::query()
            ->with('actor')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->whereHas('actor', function (Builder $q) use ($term): void {
                    $q->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                })
                    ->orWhere('summary', 'like', $term);
            });
        }

        if ($this->actionFilter !== '') {
            $query->where('action', $this->actionFilter);
        }

        if ($this->actorRoleFilter !== '') {
            $query->where('actor_role', $this->actorRoleFilter);
        }

        if (! $this->includeSystem) {
            $query->where('is_system', false);
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function actionOptions(): array
    {
        return AuditEvent::query()
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function roleOptions(): array
    {
        return [
            'super_admin' => 'Super admin',
            'customer_admin' => 'Operations admin',
            'reviewer' => 'Reviewer',
            'finance' => 'Finance',
            'auditor' => 'Auditor',
            'client_admin' => 'Dealer admin',
            'client_user' => 'Dealer user',
            'developer' => 'Developer',
        ];
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
