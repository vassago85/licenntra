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
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Staff landing page at /admin, operations first: counters, the daily
 * worklist and dealerships needing action for everyone on the licensing
 * team, then money panels for finance and owners only, then recent activity.
 */
#[Layout('layouts.portal')]
class Overview extends Component
{
    private const WORKLIST_PAGE = 15;

    #[Url(as: 'mine', except: false)]
    public bool $mineOnly = false;

    public int $worklistLimit = self::WORKLIST_PAGE;

    public function showMoreWork(): void
    {
        $this->worklistLimit += self::WORKLIST_PAGE;
    }

    public function updatedMineOnly(): void
    {
        $this->worklistLimit = self::WORKLIST_PAGE;
    }

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
        $worklist = $workload->worklist($this->mineOnly ? ['reviewer_id' => $user->id] : []);

        return view('livewire.portal.admin.overview', [
            'counters' => $workload->counters(),
            'worklist' => $worklist->take($this->worklistLimit),
            'worklistTotal' => $worklist->count(),
            'worklistOverdue' => $worklist->where('urgency', OperationsWorkloadService::URGENCY_OVERDUE)->count(),
            'canReviewDocuments' => $user->hasAnyRole(['reviewer', 'owner']),
            'canVerifyPayments' => $user->hasAnyRole(['finance', 'owner']),
            'canHandleCases' => $user->hasAnyRole(['reviewer', 'owner']),
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
