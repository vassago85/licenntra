<?php

namespace App\Livewire\Portal;

use App\Actions\PrepareSubmissionPack;
use App\Actions\SubmitToAuthority;
use App\Enums\Province;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\OperationsWorkloadService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cross-dealership outstanding tasks view.
 *
 * Reviewers, finance, admins and auditors land here to work through the
 * daily backlog: approvals, submission packs, applications waiting on
 * the authority, and files handed back ready for client collection.
 *
 * Portal replacement for the former Filament OutstandingTasks page.
 */
#[Layout('layouts.portal')]
class OutstandingTasks extends Component
{
    #[Url(as: 'tab', except: OperationsWorkloadService::TAB_OUTSTANDING)]
    public string $tab = OperationsWorkloadService::TAB_OUTSTANDING;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'account_id', except: null)]
    public ?int $accountId = null;

    #[Url(as: 'submitted_by_id', except: null)]
    public ?int $submittedById = null;

    #[Url(as: 'reviewer_id', except: null)]
    public ?int $reviewerId = null;

    #[Url(as: 'province', except: '')]
    public string $province = '';

    #[Url(as: 'overdue', except: false)]
    public bool $overdue = false;

    #[Url(as: 'kind', except: '')]
    public string $kind = '';

    #[Url(as: 'stage', except: '')]
    public string $stage = '';

    #[Url(as: 'scope', except: '')]
    public string $scope = '';

    #[Url(as: 'view', except: 'table')]
    public string $viewMode = 'table';

    /** Alpine-driven submit-to-authority modal state. */
    public ?int $submitApplicationId = null;

    public string $authorityReference = '';

    public string $authoritySubmittedAt = '';

    /** @var list<int> */
    public array $selectedApplicationIds = [];

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
    }

    public function updatedViewMode(string $value): void
    {
        $this->viewMode = $this->normaliseView($value);
    }

    /**
     * When the customer filter changes, drop any submitting-user selection
     * that is not part of the newly-selected customer. Keeps the two
     * selects in a consistent state.
     */
    public function updatedAccountId(): void
    {
        if ($this->submittedById === null || $this->accountId === null) {
            return;
        }

        $belongs = User::query()
            ->where('id', $this->submittedById)
            ->where('client_account_id', $this->accountId)
            ->exists();

        if (! $belongs) {
            $this->submittedById = null;
        }
    }

    /**
     * Prepares (or reuses) the pack for one application, or for every
     * selected row when no id is given, then opens them all in one print
     * view. Nothing is prepared if any application is not ready.
     */
    public function preparePacks(?int $applicationId = null): void
    {
        abort_unless($this->canSubmitToAuthority(), 403);

        $ids = $applicationId !== null
            ? [$applicationId]
            : array_values(array_unique(array_map('intval', $this->selectedApplicationIds)));

        if ($ids === []) {
            $this->addError('pack', 'Tick at least one application to print.');

            return;
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            DB::transaction(function () use ($ids, $user): void {
                $prepare = app(PrepareSubmissionPack::class);

                foreach (Application::query()->whereIn('id', $ids)->get() as $application) {
                    $prepare->handle($application, $user);
                }
            });
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->selectedApplicationIds = [];
        $this->redirectRoute('review.packs.print', ['ids' => implode(',', $ids)]);
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

        try {
            app(SubmitToAuthority::class)->handle(
                $application,
                $user,
                $this->authorityReference,
                Carbon::parse($this->authoritySubmittedAt),
            );
        } catch (InvalidTransition $exception) {
            $this->addError('authorityReference', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->addError('authorityReference', $exception->validator->getMessageBag()->first());

            return;
        }

        $this->cancelSubmitModal();
        session()->flash('status', 'Submitted to authority.');
    }

    public function render(): View
    {
        $service = app(OperationsWorkloadService::class);
        $user = Auth::user();

        $filters = [
            'account_id' => $this->accountId,
            'submitted_by_id' => $this->submittedById,
            'reviewer_id' => $this->reviewerId,
            'province' => $this->province !== '' ? $this->province : null,
            'overdue' => $this->overdue,
            'search' => $this->search !== '' ? $this->search : null,
            'kind' => $this->kind !== '' ? $this->kind : null,
            'stage' => $this->stage !== '' ? $this->stage : null,
            'scope' => $this->scope !== '' ? $this->scope : null,
        ];

        $tasks = $service->tasks($this->validTab(), $filters, perPage: 25);

        $counts = [
            OperationsWorkloadService::TAB_OUTSTANDING => $service->tasks(OperationsWorkloadService::TAB_OUTSTANDING, $filters, perPage: 10000)->total(),
            OperationsWorkloadService::TAB_SUBMISSION_PACKS => $service->tasks(OperationsWorkloadService::TAB_SUBMISSION_PACKS, $filters, perPage: 10000)->total(),
            OperationsWorkloadService::TAB_AWAITING_RETURN => $service->tasks(OperationsWorkloadService::TAB_AWAITING_RETURN, $filters, perPage: 10000)->total(),
            OperationsWorkloadService::TAB_RETURNED_HANDOVER => $service->tasks(OperationsWorkloadService::TAB_RETURNED_HANDOVER, $filters, perPage: 10000)->total(),
        ];

        return view('livewire.portal.outstanding-tasks', [
            'tasks' => $tasks,
            'tab' => $this->validTab(),
            'counts' => $counts,
            'tabLabels' => [
                OperationsWorkloadService::TAB_OUTSTANDING => 'Outstanding tasks',
                OperationsWorkloadService::TAB_SUBMISSION_PACKS => 'Submission packs',
                OperationsWorkloadService::TAB_AWAITING_RETURN => 'Awaiting return',
                OperationsWorkloadService::TAB_RETURNED_HANDOVER => 'Returned / ready for handover',
            ],
            'accounts' => ClientAccount::query()->orderBy('name')->pluck('name', 'id'),
            'reviewers' => User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'reviewer'))
                ->orderBy('name')
                ->pluck('name', 'id'),
            'submittingUsers' => $this->submittingUserOptions(),
            'provinces' => collect(Province::cases())
                ->mapWithKeys(fn (Province $p): array => [$p->value => $p->label()])
                ->all(),
            'canReviewDocuments' => $user !== null && $user->hasAnyRole(['reviewer', 'owner']),
            'canVerifyPayments' => $user !== null && $user->hasAnyRole(['finance', 'owner']),
            'canSubmitToAuthority' => $this->canSubmitToAuthority(),
        ]);
    }

    /**
     * The submitting-user dropdown narrows to the selected customer's users
     * when one is picked. Otherwise lists every client user with recorded
     * submissions so filtering remains useful.
     *
     * @return array<int, string>
     */
    private function submittingUserOptions(): array
    {
        $query = User::query()
            ->whereNotNull('client_account_id')
            ->orderBy('name');

        if ($this->accountId !== null) {
            $query->where('client_account_id', $this->accountId);
        } else {
            $query->whereIn(
                'id',
                Application::query()->whereNotNull('submitted_by_id')->distinct()->pluck('submitted_by_id'),
            );
        }

        return $query->pluck('name', 'id')->all();
    }

    private function canSubmitToAuthority(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->is_active
            && $user->hasAnyRole(['reviewer', 'owner']);
    }

    private function validTab(): string
    {
        return in_array($this->tab, [
            OperationsWorkloadService::TAB_OUTSTANDING,
            OperationsWorkloadService::TAB_SUBMISSION_PACKS,
            OperationsWorkloadService::TAB_AWAITING_RETURN,
            OperationsWorkloadService::TAB_RETURNED_HANDOVER,
            // Legacy aliases - older URLs/counters still work.
            OperationsWorkloadService::TAB_APPROVALS,
            OperationsWorkloadService::TAB_READY_TO_SUBMIT,
            OperationsWorkloadService::TAB_WAITING_ON_DEALERSHIP,
            OperationsWorkloadService::TAB_ALL,
        ], true) ? $this->tab : OperationsWorkloadService::TAB_OUTSTANDING;
    }

    private function normaliseView(string $value): string
    {
        return in_array($value, ['table', 'cards'], true) ? $value : 'table';
    }
}
