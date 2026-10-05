<?php

namespace App\Livewire\Portal;

use App\Actions\AssignReviewer;
use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reviewer work list. Split into four tabs so the default view only shows
 * work that is waiting on the licensing company, oldest first. Terminal
 * applications live under "Done" instead of cluttering the queue.
 */
#[Layout('layouts.portal')]
class ReviewQueue extends Component
{
    use WithPagination;

    public const TAB_REVIEW = 'review';

    public const TAB_CLIENT = 'client';

    public const TAB_PROGRESS = 'progress';

    public const TAB_DONE = 'done';

    #[Url(except: self::TAB_REVIEW)]
    public string $tab = self::TAB_REVIEW;

    #[Url(as: 'who', except: '')]
    public string $assignment = '';

    #[Url(as: 'account', except: '')]
    public string $accountId = '';

    #[Url(as: 'late', except: false)]
    public bool $pastWarning = false;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Application::class);

        abort_if($this->currentUser()->isClient(), 403);

        if (! array_key_exists($this->tab, self::tabStages())) {
            $this->tab = self::TAB_REVIEW;
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['tab', 'assignment', 'accountId', 'pastWarning', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function showTab(string $tab): void
    {
        if (! array_key_exists($tab, self::tabStages())) {
            return;
        }

        $this->tab = $tab;
        $this->pastWarning = false;
        $this->resetPage();
    }

    public function showMine(): void
    {
        $this->assignment = 'me';
        $this->pastWarning = false;
        $this->resetPage();
    }

    public function showPastWarning(): void
    {
        $this->pastWarning = true;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->assignment = '';
        $this->accountId = '';
        $this->pastWarning = false;
        $this->search = '';
        $this->resetPage();
    }

    /**
     * Assign the application to the signed-in reviewer in one click.
     */
    public function take(int $applicationId, AssignReviewer $assign): void
    {
        $user = $this->currentUser();
        $application = Application::query()->findOrFail($applicationId);

        $this->authorize('review', $application);

        $assign->handle($application, $user, $user);

        $this->statusMessage = $application->reference.' is now assigned to you.';
    }

    public function render(): View
    {
        $user = $this->currentUser();
        $settings = SystemSetting::current();

        return view('livewire.portal.review-queue', [
            'warningHours' => collect(ApplicationStage::warningStages())
                ->mapWithKeys(fn (ApplicationStage $stage): array => [$stage->value => $settings->warningHoursFor($stage)])
                ->all(),
            'rows' => $this->rows(),
            'tabs' => $this->tabCounts(),
            'stats' => $this->stats(),
            'accounts' => ClientAccount::query()->orderBy('name')->pluck('name', 'id'),
            'canTake' => $user->hasAnyRole(['reviewer', 'owner']),
            'hasFilters' => $this->assignment !== '' || $this->accountId !== '' || $this->pastWarning || $this->search !== '',
        ]);
    }

    /**
     * @return array<string, list<ApplicationStage>>
     */
    public static function tabStages(): array
    {
        return [
            self::TAB_REVIEW => [
                ApplicationStage::Submitted,
                ApplicationStage::DocumentReview,
            ],
            self::TAB_CLIENT => [
                ApplicationStage::ChangesRequested,
                ApplicationStage::QuoteSent,
                ApplicationStage::PaymentPending,
            ],
            self::TAB_PROGRESS => [
                ApplicationStage::QuoteRequired,
                ApplicationStage::QuoteAccepted,
                ApplicationStage::PaymentVerified,
                ApplicationStage::DatafixInProgress,
                ApplicationStage::SubmittedToAuthority,
                ApplicationStage::AuthorityQuery,
                ApplicationStage::Approved,
                ApplicationStage::ReadyForCollection,
            ],
            self::TAB_DONE => [
                ApplicationStage::Completed,
                ApplicationStage::Cancelled,
                ApplicationStage::Archived,
            ],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Application>
     */
    private function rows(): LengthAwarePaginator
    {
        $query = $this->filteredQuery()
            ->whereIn('stage', self::tabStages()[$this->tab])
            ->with(['vehicle', 'clientAccount', 'reviewer'])
            ->withMax('stageHistories as stage_entered_at', 'created_at');

        $query = $this->tab === self::TAB_DONE
            ? $query->latest('updated_at')
            : $query->oldest('updated_at');

        return $query->paginate(20);
    }

    /**
     * Base query with every filter except the tab, so tab badges can show
     * how many rows each tab would hold under the current filters.
     */
    private function filteredQuery(): Builder
    {
        return Application::query()
            ->where('stage', '!=', ApplicationStage::Draft)
            ->when($this->assignment === 'me', fn (Builder $query) => $query->where('assigned_reviewer_id', Auth::id()))
            ->when($this->assignment === 'unassigned', fn (Builder $query) => $query->whereNull('assigned_reviewer_id'))
            ->when($this->accountId !== '', fn (Builder $query) => $query->where('client_account_id', (int) $this->accountId))
            ->when($this->pastWarning, fn (Builder $query) => $this->wherePastWarning($query))
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.trim($this->search).'%';

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('reference', 'like', $term)
                        ->orWhereHas('vehicle', fn (Builder $vehicle) => $vehicle
                            ->where('vin', 'like', $term)
                            ->orWhere('vehicle_register_number', 'like', $term)
                            ->orWhere('make', 'like', $term)
                            ->orWhere('model', 'like', $term))
                        ->orWhereHas('clientAccount', fn (Builder $account) => $account->where('name', 'like', $term));
                });
            });
    }

    /**
     * @return array<string, int>
     */
    private function tabCounts(): array
    {
        $counts = [];

        foreach (self::tabStages() as $tab => $stages) {
            $counts[$tab] = $this->filteredQuery()->whereIn('stage', $stages)->count();
        }

        return $counts;
    }

    /**
     * Open applications that have sat in their current step longer than
     * the warning time configured for it in System settings.
     *
     * @param  Builder<Application>  $query
     * @return Builder<Application>
     */
    private function wherePastWarning(Builder $query): Builder
    {
        return $query
            ->whereIn('stage', ApplicationStage::warningStages())
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        $active = Application::query()
            ->whereNotIn('stage', [
                ApplicationStage::Draft,
                ApplicationStage::Completed,
                ApplicationStage::Cancelled,
                ApplicationStage::Archived,
            ]);

        return [
            'awaiting_review' => (clone $active)->whereIn('stage', self::tabStages()[self::TAB_REVIEW])->count(),
            'with_client' => (clone $active)->whereIn('stage', self::tabStages()[self::TAB_CLIENT])->count(),
            'assigned_to_me' => (clone $active)->where('assigned_reviewer_id', Auth::id())->count(),
            'past_warning' => $this->wherePastWarning(Application::query())->count(),
        ];
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
