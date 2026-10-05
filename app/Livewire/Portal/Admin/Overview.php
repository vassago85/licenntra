<?php

namespace App\Livewire\Portal\Admin;

use App\Models\AuditEvent;
use App\Models\Payment;
use App\Models\User;
use App\Services\FeatureFlags;
use App\Services\OperationsWorkloadService;
use App\Services\ReceivablesService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Staff landing page at /admin, operations first: counters and dealerships
 * needing action for everyone on the licensing team, then money panels for
 * finance and owners only, then recent activity.
 */
#[Layout('layouts.portal')]
class Overview extends Component
{
    public function mount(): void
    {
        if ($this->staffUser() === null && Auth::user()?->hasRole('developer')) {
            throw new HttpResponseException(redirect()->route('platform.billing'));
        }

        abort_if($this->staffUser() === null, 403);
    }

    public function render(OperationsWorkloadService $workload, ReceivablesService $receivables): View
    {
        $user = $this->staffUser();
        abort_if($user === null, 403);

        $seesMoney = $user->hasAnyRole(['finance', 'owner']);

        return view('livewire.portal.admin.overview', [
            'counters' => $workload->counters(),
            'dealerships' => $workload->accountRows(['needs_action_only' => true])->take(10),
            'workload' => $workload,
            'seesMoney' => $seesMoney,
            'quotesEnabled' => FeatureFlags::quotesEnabled(),
            'summary' => $seesMoney ? $receivables->summary() : null,
            'customerBalances' => $seesMoney ? $receivables->customerBalances() : collect(),
            'topCustomers' => $seesMoney ? $receivables->topCustomers() : collect(),
            'transactions' => $seesMoney
                ? Payment::query()
                    ->with(['application:id,reference,client_account_id', 'application.clientAccount:id,name', 'verifier:id,name'])
                    ->latest('created_at')
                    ->limit(15)
                    ->get()
                : collect(),
            'activity' => AuditEvent::query()->with('actor')->latest('occurred_at')->limit(10)->get(),
        ]);
    }

    private function staffUser(): ?User
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->is_active || $user->isOffboarded() || ! $user->isLicensingStaff()) {
            return null;
        }

        return $user;
    }
}
