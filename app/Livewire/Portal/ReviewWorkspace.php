<?php

namespace App\Livewire\Portal;

use App\Actions\AddApplicationNote;
use App\Actions\AssignReviewer;
use App\Actions\ChangeServiceType;
use App\Actions\CompleteDatafix;
use App\Actions\ConfirmDatafix;
use App\Actions\PrepareSubmissionPack;
use App\Actions\QueryDatafix;
use App\Actions\RecordAuthorityReturn;
use App\Actions\ResolveAuthorityQuery;
use App\Actions\ReviewDocument;
use App\Actions\SubmitToAuthority;
use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Enums\DocumentStatus;
use App\Enums\RejectionReason;
use App\Enums\ServiceType;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditEvent;
use App\Services\FeatureFlags;
use App\Services\OperationsWorkloadService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.portal')]
class ReviewWorkspace extends Component
{
    public Application $application;

    public string $clientNote = '';

    public string $internalNote = '';

    public string $reason = '';

    public string $tare_kg = '';

    public string $body_type = '';

    public string $gvm_kg = '';

    public string $value_choice = '';

    public string $authority_reference = '';

    public string $query_note = '';

    public string $service_type = '';

    public string $service_reason = '';

    /** @var array<int, string> */
    public array $rejectionReasons = [];

    /** @var array<int, string> */
    public array $rejectionComments = [];

    public bool $dangerousGoodsStamped = false;

    public string $submitReference = '';

    public string $submitAt = '';

    public string $resolution = '';

    public string $returnedAt = '';

    public string $returnNotes = '';

    public function mount(Application $application): void
    {
        $this->authorize('view', $application);

        if (auth()->user()?->isClient()) {
            abort(403);
        }

        $this->application = $application->load(['datafix', 'vehicle']);
        $record = $application->datafix;
        $this->tare_kg = (string) ($record?->tare_kg ?? $application->vehicle?->tare_kg ?? '');
        $this->body_type = (string) ($record?->body_type ?? $application->vehicle?->body_type ?? '');
        $this->gvm_kg = (string) ($record?->gvm_kg ?? $application->vehicle?->gvm_kg ?? '');
        $this->service_type = $application->service_type?->value ?? '';
        $this->submitAt = now()->format('Y-m-d\TH:i');
        $this->returnedAt = now()->format('Y-m-d\TH:i');
    }

    public function preparePack(): void
    {
        $this->authorize('review', $this->application);

        try {
            app(PrepareSubmissionPack::class)->handle($this->application, auth()->user());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->redirectRoute('review.packs.print', ['ids' => $this->application->id]);
    }

    public function submitToAuthority(): void
    {
        $this->authorize('review', $this->application);

        $this->validate([
            'submitReference' => ['required', 'string', 'max:255'],
            'submitAt' => ['required', 'date', 'before_or_equal:now'],
        ], [
            'submitReference.required' => 'Enter the reference the department gave on lodgement.',
            'submitAt.before_or_equal' => 'The submission date cannot be in the future.',
        ]);

        try {
            $this->application = app(SubmitToAuthority::class)->handle(
                $this->application,
                auth()->user(),
                $this->submitReference,
                Carbon::parse($this->submitAt),
            );
        } catch (InvalidTransition $exception) {
            $this->addError('submitReference', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->submitReference = '';
        session()->flash('status', 'Lodged with the department.');
    }

    public function resolveQuery(): void
    {
        $this->authorize('review', $this->application);

        try {
            $this->application = app(ResolveAuthorityQuery::class)->handle($this->application, auth()->user(), $this->resolution);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->resolution = '';
    }

    public function recordReturn(): void
    {
        $this->authorize('review', $this->application);

        $this->validate(['returnedAt' => ['required', 'date']], ['returnedAt.required' => 'Enter when the documents arrived back.']);

        try {
            $this->application = app(RecordAuthorityReturn::class)->handle(
                $this->application,
                auth()->user(),
                Carbon::parse($this->returnedAt),
                $this->returnNotes,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        } catch (InvalidTransition $exception) {
            $this->addError('returnedAt', $exception->getMessage());

            return;
        }

        $this->returnNotes = '';
        session()->flash('status', 'Receipt recorded. Ready for handover to the customer.');
    }

    public function acceptDocument(int $documentId): void
    {
        $document = $this->application->documents()->with('documentType')->findOrFail($documentId);
        $this->authorize('review', $document);

        try {
            app(ReviewDocument::class)->handle(
                $document,
                auth()->user(),
                DocumentStatus::Accepted,
                dangerousGoodsStamped: $this->stampConfirmation($document),
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->application->refresh();
    }

    public function rejectDocument(int $documentId): void
    {
        $document = $this->application->documents()->findOrFail($documentId);
        $this->authorize('review', $document);
        $reason = RejectionReason::tryFrom($this->rejectionReasons[$documentId] ?? '');

        try {
            app(ReviewDocument::class)->handle(
                $document,
                auth()->user(),
                DocumentStatus::Rejected,
                $reason,
                $this->rejectionComments[$documentId] ?? null,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->application->refresh();
    }

    public function confirmDatafix(): void
    {
        $this->authorize('review', $this->application);

        try {
            app(ConfirmDatafix::class)->handle(
                $this->application,
                auth()->user(),
                (int) $this->tare_kg,
                $this->body_type,
                (int) $this->gvm_kg,
                $this->value_choice !== '' ? $this->value_choice : null,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->application->refresh();
        session()->flash('status', 'Datafix values confirmed.');
    }

    public function completeDatafix(): void
    {
        $this->authorize('review', $this->application);

        try {
            $this->application = app(CompleteDatafix::class)->handle(
                $this->application,
                auth()->user(),
                $this->authority_reference !== '' ? $this->authority_reference : null,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());
        }
    }

    public function queryDatafix(): void
    {
        $this->authorize('review', $this->application);

        try {
            $this->application = app(QueryDatafix::class)->handle($this->application, auth()->user(), $this->query_note);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());
        }
    }

    public function changeService(): void
    {
        $this->authorize('review', $this->application);
        $service = ServiceType::tryFrom($this->service_type);

        if ($service === null) {
            $this->addError('service_type', 'Choose a service type.');

            return;
        }

        try {
            $this->application = app(ChangeServiceType::class)->handle(
                $this->application,
                auth()->user(),
                $service,
                $this->service_reason,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());
        }
    }

    public function addNote(string $visibility): void
    {
        $this->authorize('review', $this->application);
        $body = $visibility === 'internal' ? $this->internalNote : $this->clientNote;

        try {
            app(AddApplicationNote::class)->handle($this->application, auth()->user(), $body, $visibility);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        if ($visibility === 'internal') {
            $this->internalNote = '';
        } else {
            $this->clientNote = '';
        }

        $this->application->refresh();
    }

    public function assignToMe(): void
    {
        $this->authorize('review', $this->application);
        $this->application = app(AssignReviewer::class)->handle($this->application, auth()->user(), auth()->user());
    }

    public function advance(string $stage): void
    {
        $this->authorize('review', $this->application);

        try {
            $this->application = app(TransitionApplication::class)->handle(
                $this->application,
                ApplicationStage::from($stage),
                auth()->user(),
                $this->reason !== '' ? $this->reason : null,
            );
        } catch (InvalidTransition $exception) {
            $this->addError('stage', $exception->getMessage());

            return;
        }

        $this->reason = '';
    }

    /**
     * Buttons for the generic "Move stage" panel. Stages reached through a
     * guided action (payment, department lodgement, physical receipt) are
     * left out so those records can't be skipped.
     *
     * @return list<array{stage: ApplicationStage, label: string}>
     */
    private function moveStageOptions(): array
    {
        $application = $this->application;
        $guided = [
            ApplicationStage::QuoteAccepted,
            ApplicationStage::PaymentVerified,
            ApplicationStage::SubmittedToAuthority,
            ApplicationStage::ReadyForCollection,
        ];
        $options = [];

        foreach ($application->stage->successors() as $next) {
            if ($next === ApplicationStage::PaymentVerified && $application->billsWithoutPaymentCheck() && $application->payments()->doesntExist()) {
                $options[] = ['stage' => $next, 'label' => 'Bill and continue'];

                continue;
            }

            if (in_array($next, $guided, true) || ($next === ApplicationStage::QuoteRequired && ! FeatureFlags::quotesEnabled())) {
                continue;
            }

            $options[] = ['stage' => $next, 'label' => $next->label()];
        }

        return $options;
    }

    private function stampConfirmation(ApplicationDocument $document): ?bool
    {
        if (! $document->requiresDangerousGoodsStamp()) {
            return null;
        }

        return $document->dangerous_goods_stamped || $this->dangerousGoodsStamped;
    }

    public function render(): View
    {
        $this->application->load([
            'documents.documentType',
            'documents.currentVersion',
            'vehicle',
            'datafix',
            'notes.author',
            'clientAccount',
            'businessClient',
            'titleHolder',
            'quotes.lines',
            'payments',
            'stageHistories',
        ]);

        $packStage = in_array($this->application->stage, [
            ApplicationStage::PaymentVerified,
            ApplicationStage::DatafixInProgress,
            ApplicationStage::AuthorityQuery,
        ], true);

        return view('livewire.portal.review-workspace', [
            'packStage' => $packStage,
            'packBlockers' => $packStage ? app(OperationsWorkloadService::class)->authorityBlockers($this->application) : [],
            'currentPack' => $packStage ? $this->application->currentSubmissionPack() : null,
            'queryNote' => $this->application->stage === ApplicationStage::AuthorityQuery ? $this->application->latestAuthorityQueryNote() : null,
            'quotesEnabled' => FeatureFlags::quotesEnabled(),
            'moveStages' => $this->moveStageOptions(),
            'money' => Money::class,
            'reasons' => RejectionReason::cases(),
            'services' => ServiceType::cases(),
            'audit' => AuditEvent::query()
                ->where('subject_type', Application::class)
                ->where('subject_id', $this->application->id)
                ->latest('occurred_at')
                ->limit(30)
                ->get(),
        ]);
    }
}
