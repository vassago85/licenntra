<?php

namespace App\Livewire\Portal;

use App\Actions\SubmitToAuthority;
use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\OperationsWorkloadService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Per-dealership card board.
 *
 * Internal staff talk to dealerships about a specific vehicle (quoted by
 * registration number or VIN), not by application reference. This page
 * narrows the view to one dealership and surfaces every active application
 * as a card with the registration number as the hero field.
 *
 * The next action per card is derived from the stage and gated against
 * the viewing user's role (reviewer / finance / admin).
 *
 * Portal replacement for the former Filament DealershipCards page.
 */
#[Layout('layouts.portal')]
class DealershipCards extends Component
{
    #[Url(as: 'account_id', except: null)]
    public ?int $accountId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'stage', except: '')]
    public string $stage = '';

    #[Url(as: 'overdue', except: false)]
    public bool $overdue = false;

    #[Url(as: 'mine', except: false)]
    public bool $mine = false;

    #[Url(as: 'view', except: 'cards')]
    public string $viewMode = 'cards';

    /** Alpine-driven submit-to-authority modal state. */
    public ?int $submitApplicationId = null;

    public string $authorityReference = '';

    public string $authoritySubmittedAt = '';

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless(
            $user instanceof User
                && $user->is_active
                && $user->hasAnyRole(['owner', 'reviewer', 'finance']),
            403,
        );

        $this->viewMode = $this->normaliseView($this->viewMode);

        if ($this->accountId !== null && $this->account() === null) {
            abort(404);
        }
    }

    public function updatedViewMode(string $value): void
    {
        $this->viewMode = $this->normaliseView($value);
    }

    public function openSubmitModal(int $applicationId): void
    {
        abort_unless($this->canSubmitToAuthority(), 403);

        $this->submitApplicationId = $applicationId;
        $this->authorityReference = '';
        $this->authoritySubmittedAt = now()->format('Y-m-d\TH:i');
        $this->resetErrorBag(['authorityReference', 'authoritySubmittedAt']);
    }

    public function cancelSubmitModal(): void
    {
        $this->submitApplicationId = null;
        $this->authorityReference = '';
        $this->authoritySubmittedAt = '';
        $this->resetErrorBag(['authorityReference', 'authoritySubmittedAt']);
    }

    public function confirmSubmitToAuthority(): void
    {
        abort_unless($this->canSubmitToAuthority(), 403);

        if ($this->submitApplicationId === null) {
            return;
        }

        $this->validate([
            'authorityReference' => ['required', 'string', 'max:255'],
            'authoritySubmittedAt' => ['required', 'date', 'before_or_equal:now'],
        ], [
            'authorityReference.required' => 'Enter the authority reference captured on hand-off.',
            'authoritySubmittedAt.before_or_equal' => 'The submission date cannot be in the future.',
        ]);

        $application = Application::query()->find($this->submitApplicationId);
        if ($application === null) {
            $this->cancelSubmitModal();

            return;
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        app(SubmitToAuthority::class)->handle(
            $application,
            $user,
            $this->authorityReference,
            Carbon::parse($this->authoritySubmittedAt),
        );

        $this->cancelSubmitModal();
        session()->flash('status', 'Submitted to authority.');
    }

    public function render(): View
    {
        $account = $this->account();
        $service = app(OperationsWorkloadService::class);
        $user = Auth::user();

        $filters = [
            'search' => $this->search !== '' ? $this->search : null,
            'stage' => $this->stage !== '' ? $this->stage : null,
            'overdue' => $this->overdue,
            'assigned_to' => $this->mine && $user?->hasRole('reviewer') ? $user->id : null,
        ];

        $cards = $account !== null
            ? $service->applicationCards($account, $filters)
            : collect();

        $stageOptions = collect(ApplicationStage::cases())
            ->reject(fn (ApplicationStage $s): bool => $s->isTerminal())
            ->mapWithKeys(fn (ApplicationStage $s): array => [$s->value => $s->label()])
            ->all();

        return view('livewire.portal.dealership-cards', [
            'account' => $account,
            'cards' => $cards,
            'stageOptions' => $stageOptions,
            'reviewerPicker' => $user?->hasRole('reviewer') ?? false,
            'canReviewDocuments' => $user?->hasAnyRole(['reviewer', 'owner']) ?? false,
            'canVerifyPayments' => $user?->hasAnyRole(['finance', 'owner']) ?? false,
            'canSubmitToAuthority' => $this->canSubmitToAuthority(),
            'canBuildQuotes' => $user?->hasAnyRole(['reviewer', 'owner']) ?? false,
            'allDealerships' => ClientAccount::query()->orderBy('name')->pluck('name', 'id'),
            'summary' => [
                'total' => $cards->count(),
                'overdue' => $cards->where('overdue', true)->count(),
                'payment_owed' => $cards->where('payment_owed', true)->count(),
                'payment_awaiting_verification' => $cards->where('payment_awaiting_verification', true)->count(),
                'ready_for_authority' => $cards->where('is_ready_for_authority', true)->count(),
            ],
            'heading' => $account ? $account->name : 'Dealership board',
            'subheading' => $this->subheading($account),
        ]);
    }

    private function subheading(?ClientAccount $account): ?string
    {
        if ($account === null) {
            return null;
        }

        $parts = array_filter([
            $account->type?->label(),
            $account->isOnAccount()
                ? 'On statement'.($account->payment_terms_days ? ' ('.$account->payment_terms_days.'-day terms)' : '')
                : 'Pay per transaction',
        ]);

        return implode(' · ', $parts);
    }

    private function canSubmitToAuthority(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->is_active
            && $user->hasAnyRole(['reviewer', 'owner']);
    }

    private function account(): ?ClientAccount
    {
        if ($this->accountId === null) {
            return null;
        }

        return ClientAccount::query()->find($this->accountId);
    }

    private function normaliseView(string $value): string
    {
        return in_array($value, ['table', 'cards'], true) ? $value : 'cards';
    }
}
