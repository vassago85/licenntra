<?php

namespace App\Livewire\Portal;

use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\DocumentStatus;
use App\Enums\RejectionReason;
use App\Enums\ServiceType;
use App\Enums\VehicleCategory;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\BusinessClient;
use App\Models\Quote;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.portal')]
class Dashboard extends Component
{
    use WithPagination;

    public string $search = '';

    /** @var 'open'|'needs_action'|'commercial'|'passenger'|'completed' */
    #[Url(as: 'tab', keep: false)]
    public string $filter = 'open';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isClient(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        if (! in_array($filter, ['open', 'needs_action', 'commercial', 'passenger', 'completed'], true)) {
            return;
        }

        $this->filter = $filter;
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorize('viewAny', Application::class);

        $openStages = array_values(array_filter(
            ApplicationStage::cases(),
            fn (ApplicationStage $s): bool => ! $s->isTerminal(),
        ));

        $open = Application::query()->whereIn('stage', $openStages);
        $openCount = (clone $open)->count();

        $rejectedDocs = ApplicationDocument::query()
            ->where('status', DocumentStatus::Rejected)
            ->whereHas('application', fn ($q) => $q->whereIn('stage', $openStages))
            ->count();
        $quotesWaiting = Quote::query()
            ->where('status', 'sent')
            ->whereHas('application', fn ($q) => $q->whereIn('stage', $openStages))
            ->count();
        $drafts = (clone $open)->where('stage', ApplicationStage::Draft)->count();
        $needsAction = $rejectedDocs + $quotesWaiting + $drafts;

        $inReview = (clone $open)->whereIn('stage', [
            ApplicationStage::Submitted,
            ApplicationStage::DocumentReview,
            ApplicationStage::ChangesRequested,
        ])->count();
        $atAuthority = (clone $open)->whereIn('stage', [
            ApplicationStage::SubmittedToAuthority,
            ApplicationStage::AuthorityQuery,
            ApplicationStage::Approved,
        ])->count();
        $atPayment = (clone $open)->whereIn('stage', [
            ApplicationStage::QuoteRequired,
            ApplicationStage::QuoteSent,
            ApplicationStage::QuoteAccepted,
            ApplicationStage::PaymentPending,
            ApplicationStage::PaymentVerified,
        ])->count();
        $withCompany = $inReview + $atAuthority + $atPayment;

        $datafixAwaiting = (clone $open)->where('datafix_status', DatafixStatus::AwaitingDocuments)->count();
        $datafixInProgress = (clone $open)->whereIn('datafix_status', [
            DatafixStatus::InProgress,
            DatafixStatus::Queried,
            DatafixStatus::Ready,
        ])->count();
        $datafix = $datafixAwaiting + $datafixInProgress;

        $readyWithDisc = (clone $open)->where('stage', ApplicationStage::ReadyForCollection)
            ->where('service_type', ServiceType::RegisterAndLicense)->count();
        $readyRegOnly = (clone $open)->where('stage', ApplicationStage::ReadyForCollection)
            ->where('service_type', ServiceType::RegisterOnly)->count();
        $ready = $readyWithDisc + $readyRegOnly;

        $completedStages = array_values(array_filter(
            ApplicationStage::cases(),
            fn (ApplicationStage $s): bool => $s->isTerminal(),
        ));

        $completedCount = Application::query()->whereIn('stage', $completedStages)->count();

        $rows = Application::query()
            ->with(['vehicle', 'businessClient', 'documents', 'deliverables'])
            ->when($this->filter === 'completed',
                fn ($q) => $q->whereIn('stage', $completedStages),
                fn ($q) => $q->whereIn('stage', $openStages),
            )
            ->when($this->filter === 'needs_action', function ($q) {
                $q->where(function ($inner): void {
                    $inner->whereIn('stage', [
                        ApplicationStage::Draft,
                        ApplicationStage::ChangesRequested,
                        ApplicationStage::QuoteSent,
                        ApplicationStage::PaymentPending,
                    ])->orWhereHas('documents', function ($docs): void {
                        $docs->where('status', DocumentStatus::Rejected);
                    });
                });
            })
            ->when($this->filter === 'commercial', fn ($q) => $q->where('vehicle_category', VehicleCategory::Commercial))
            ->when($this->filter === 'passenger', fn ($q) => $q->where('vehicle_category', VehicleCategory::Passenger))
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('reference', 'like', $term)
                        ->orWhereHas('vehicle', function ($vehicle) use ($term): void {
                            $vehicle->where('vin', 'like', $term)
                                ->orWhere('vehicle_register_number', 'like', $term);
                        })
                        ->orWhereHas('businessClient', function ($b) use ($term): void {
                            $b->where('business_name', 'like', $term);
                        });
                });
            })
            ->latest('updated_at')
            ->paginate(12);

        $actionItems = $this->buildActionItems($openStages);

        return view('livewire.portal.dashboard', [
            'now' => Carbon::now(),
            'openCount' => $openCount,
            'completedCount' => $completedCount,
            'tiles' => [
                'needsAction' => [
                    'total' => $needsAction,
                    'parts' => array_filter([
                        $rejectedDocs ? "{$rejectedDocs} rejected document".($rejectedDocs === 1 ? '' : 's') : null,
                        $quotesWaiting ? "{$quotesWaiting} quote".($quotesWaiting === 1 ? '' : 's') : null,
                        $drafts ? "{$drafts} draft".($drafts === 1 ? '' : 's') : null,
                    ]),
                ],
                'withCompany' => [
                    'total' => $withCompany,
                    'parts' => array_filter([
                        $inReview ? "{$inReview} in review" : null,
                        $atAuthority ? "{$atAuthority} at the authority" : null,
                        $atPayment ? "{$atPayment} payment" : null,
                    ]),
                ],
                'datafix' => [
                    'total' => $datafix,
                    'parts' => array_filter([
                        $datafixAwaiting ? "{$datafixAwaiting} waiting on documents" : null,
                        $datafixInProgress ? "{$datafixInProgress} in progress" : null,
                    ]),
                ],
                'ready' => [
                    'total' => $ready,
                    'parts' => array_filter([
                        $readyWithDisc ? "{$readyWithDisc} with licence disc" : null,
                        $readyRegOnly ? "{$readyRegOnly} registration only" : null,
                    ]),
                ],
            ],
            'rows' => $rows,
            'actionItems' => $actionItems,
            'retention' => BusinessClient::query()
                ->whereNotNull('retention_expires_at')
                ->orderBy('retention_expires_at')
                ->limit(6)
                ->get(),
        ]);
    }

    /**
     * Build the "Needs your action" sidebar items: rejected docs, waiting quotes, drafts.
     *
     * @param  list<ApplicationStage>  $openStages
     * @return list<array{ref:string, label:string, tone:string, note:string, cta:string, url:string}>
     */
    private function buildActionItems(array $openStages): array
    {
        $items = [];

        $rejected = ApplicationDocument::query()
            ->with(['application', 'documentType'])
            ->where('status', DocumentStatus::Rejected)
            ->whereHas('application', fn ($q) => $q->whereIn('stage', $openStages))
            ->latest('updated_at')
            ->limit(4)
            ->get();

        foreach ($rejected as $doc) {
            if ($doc->application === null) {
                continue;
            }
            $reason = $doc->rejection_reason instanceof RejectionReason
                ? $doc->rejection_reason->label()
                : 'rejected';
            $items[] = [
                'ref' => $doc->application->reference,
                'label' => 'Rejected',
                'tone' => 'danger',
                'note' => ($doc->documentType?->name ?? 'Document').' — '.strtolower($reason).'. Upload a clearer scan.',
                'cta' => 'Replace document',
                'url' => route('applications.show', $doc->application),
            ];
        }

        $quotes = Quote::query()
            ->with('application')
            ->where('status', 'sent')
            ->whereHas('application', fn ($q) => $q->whereIn('stage', $openStages))
            ->latest('created_at')
            ->limit(3)
            ->get();

        foreach ($quotes as $quote) {
            if ($quote->application === null) {
                continue;
            }
            $expires = $quote->expires_at ? $quote->expires_at->format('j M') : null;
            $items[] = [
                'ref' => $quote->application->reference,
                'label' => 'Quote',
                'tone' => 'warning',
                'note' => $expires ? "Awaiting your decision. Expires {$expires}." : 'Awaiting your decision.',
                'cta' => 'Review quote',
                'url' => route('applications.show', $quote->application),
            ];
        }

        $drafts = Application::query()
            ->where('stage', ApplicationStage::Draft)
            ->latest('updated_at')
            ->limit(3)
            ->get();

        foreach ($drafts as $draft) {
            $missing = $this->describeDraftGaps($draft);
            $items[] = [
                'ref' => $draft->reference,
                'label' => 'Draft',
                'tone' => 'neutral',
                'note' => $missing,
                'cta' => 'Continue draft',
                'url' => route('applications.show', $draft),
            ];
        }

        return array_slice($items, 0, 5);
    }

    private function describeDraftGaps(Application $draft): string
    {
        $gaps = [];
        if ($draft->vehicle?->vin === null || $draft->vehicle?->vin === '') {
            $gaps[] = 'VIN';
        }
        $missingDocs = $draft->documents->where('status', DocumentStatus::Missing)->count();
        if ($missingDocs > 0) {
            $gaps[] = $missingDocs.' document'.($missingDocs === 1 ? '' : 's');
        }
        if ($gaps === []) {
            return 'Submit when ready.';
        }

        return implode(' and ', $gaps).' missing.';
    }
}
