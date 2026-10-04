<?php

namespace App\Livewire\Portal;

use App\Actions\AcceptQuote;
use App\Actions\AddApplicationNote;
use App\Actions\StoreDeliverable;
use App\Actions\StoreDocument;
use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Enums\DeliverableKind;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\DeliverableDocument;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.portal')]
class ApplicationShow extends Component
{
    use WithFileUploads;

    public Application $application;

    public string $note = '';

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $uploads = [];

    public ?TemporaryUploadedFile $newDeliverable = null;

    public string $newDeliverableKind = 'natis_certificate';

    public string $newDeliverableLabel = '';

    public string $newDeliverableNotes = '';

    public function mount(Application $application): void
    {
        $this->authorize('view', $application);
        $this->application = $application;
    }

    public function upload(int $documentId): void
    {
        $document = $this->application->documents()->findOrFail($documentId);
        $this->authorize('upload', $document);
        $file = $this->uploads[$documentId] ?? null;

        if (! $file instanceof TemporaryUploadedFile) {
            $this->addError('upload', 'Choose a PDF, JPG, or PNG.');

            return;
        }

        try {
            app(StoreDocument::class)->handle($document, $file, auth()->user());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        unset($this->uploads[$documentId]);
        $this->application->refresh();
    }

    public function addNote(): void
    {
        $this->authorize('view', $this->application);

        try {
            app(AddApplicationNote::class)->handle($this->application, auth()->user(), $this->note, 'client');
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->note = '';
        $this->application->refresh();
    }

    public function acceptQuote(): void
    {
        $this->authorize('acceptQuote', $this->application);

        try {
            $this->application = app(AcceptQuote::class)->handle($this->application, auth()->user());
        } catch (InvalidTransition $exception) {
            $this->addError('quote', $exception->getMessage());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());
        }
    }

    public function storeDeliverable(): void
    {
        $this->authorize('upload', [DeliverableDocument::class, $this->application]);

        if (! $this->newDeliverable instanceof TemporaryUploadedFile) {
            $this->addError('newDeliverable', 'Choose a PDF, JPG, or PNG.');

            return;
        }

        $kind = DeliverableKind::tryFrom($this->newDeliverableKind);

        if ($kind === null) {
            $this->addError('newDeliverableKind', 'Choose a document type.');

            return;
        }

        try {
            app(StoreDeliverable::class)->handle(
                $this->application,
                $this->newDeliverable,
                $kind,
                auth()->user(),
                $this->newDeliverableLabel !== '' ? $this->newDeliverableLabel : null,
                $this->newDeliverableNotes !== '' ? $this->newDeliverableNotes : null,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->reset(['newDeliverable', 'newDeliverableLabel', 'newDeliverableNotes']);
        $this->newDeliverableKind = 'natis_certificate';
        $this->application->refresh();
    }

    public function deleteDeliverable(int $deliverableId): void
    {
        $deliverable = $this->application->deliverables()->findOrFail($deliverableId);

        $this->authorize('delete', $deliverable);

        $deliverable->delete();

        $this->application->refresh();
    }

    public function resubmit(): void
    {
        $this->authorize('update', $this->application);

        try {
            $this->application = app(TransitionApplication::class)->handle(
                $this->application,
                ApplicationStage::DocumentReview,
                auth()->user(),
            );
        } catch (InvalidTransition $exception) {
            $this->addError('submit', $exception->getMessage());
        }
    }

    public function render(): View
    {
        $this->application->load([
            'documents.documentType',
            'documents.currentVersion',
            'deliverables.uploader',
            'vehicle',
            'quotes.lines',
            'notes.author',
            'businessClient',
            'titleHolder',
        ]);

        return view('livewire.portal.application-show', [
            'stages' => ApplicationStage::cases(),
            'money' => Money::class,
            'quote' => $this->application->quotes()->where('status', 'sent')->latest('id')->first(),
            'deliverableKinds' => DeliverableKind::cases(),
            'canUploadDeliverable' => auth()->user()?->can('upload', [DeliverableDocument::class, $this->application]) ?? false,
        ]);
    }
}
