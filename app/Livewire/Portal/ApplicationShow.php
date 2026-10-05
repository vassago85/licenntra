<?php

namespace App\Livewire\Portal;

use App\Actions\AcceptQuote;
use App\Actions\AddApplicationNote;
use App\Actions\MarkInvoicePaid;
use App\Actions\MarkInvoiceUnpaid;
use App\Actions\StoreDeliverable;
use App\Actions\StoreDocument;
use App\Actions\StoreInvoice;
use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Enums\DeliverableKind;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\DeliverableDocument;
use App\Models\Invoice;
use App\Models\User;
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

    public ?TemporaryUploadedFile $newInvoice = null;

    public string $newInvoiceNumber = '';

    public string $newInvoiceRecipientUserId = '';

    public ?int $invoicePayingId = null;

    public string $invoicePaidReference = '';

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

    public function storeInvoice(): void
    {
        $this->authorize('upload', [Invoice::class, $this->application]);

        if (! $this->newInvoice instanceof TemporaryUploadedFile) {
            $this->addError('newInvoice', 'Choose a PDF, JPG, or PNG.');

            return;
        }

        try {
            app(StoreInvoice::class)->handle(
                $this->application,
                $this->newInvoice,
                $this->newInvoiceNumber,
                auth()->user(),
                $this->newInvoiceRecipientUserId !== '' ? (int) $this->newInvoiceRecipientUserId : null,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->reset(['newInvoice', 'newInvoiceNumber', 'newInvoiceRecipientUserId']);
        $this->application->refresh();
    }

    public function startInvoicePayment(int $invoiceId): void
    {
        $invoice = $this->application->invoices()->findOrFail($invoiceId);
        $this->authorize('markPaid', $invoice);
        $this->invoicePayingId = $invoice->id;
        $this->invoicePaidReference = '';
    }

    public function cancelInvoicePayment(): void
    {
        $this->invoicePayingId = null;
        $this->invoicePaidReference = '';
    }

    public function markInvoicePaid(): void
    {
        if ($this->invoicePayingId === null) {
            return;
        }

        $invoice = $this->application->invoices()->findOrFail($this->invoicePayingId);
        $this->authorize('markPaid', $invoice);

        try {
            app(MarkInvoicePaid::class)->handle(
                $invoice,
                auth()->user(),
                $this->invoicePaidReference !== '' ? $this->invoicePaidReference : null,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->invoicePayingId = null;
        $this->invoicePaidReference = '';
        $this->application->refresh();
    }

    public function markInvoiceUnpaid(int $invoiceId): void
    {
        $invoice = $this->application->invoices()->findOrFail($invoiceId);
        $this->authorize('markUnpaid', $invoice);

        app(MarkInvoiceUnpaid::class)->handle($invoice, auth()->user());

        $this->application->refresh();
    }

    public function deleteInvoice(int $invoiceId): void
    {
        $invoice = $this->application->invoices()->findOrFail($invoiceId);
        $this->authorize('delete', $invoice);

        $invoice->delete();

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
            'invoices.uploader',
            'invoices.paidBy',
            'invoices.recipient',
            'vehicle',
            'quotes.lines',
            'notes.author',
            'businessClient',
            'titleHolder',
            'clientAccount.stockController',
        ]);

        $canUploadInvoice = auth()->user()?->can('upload', [Invoice::class, $this->application]) ?? false;
        $dealershipUsers = collect();
        $stockControllerId = $this->application->clientAccount?->stock_controller_user_id;

        if ($canUploadInvoice) {
            $dealershipUsers = User::query()
                ->where('client_account_id', $this->application->client_account_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']);

            if ($this->newInvoiceRecipientUserId === '' && $stockControllerId !== null) {
                $this->newInvoiceRecipientUserId = (string) $stockControllerId;
            }
        }

        return view('livewire.portal.application-show', [
            'stages' => ApplicationStage::cases(),
            'money' => Money::class,
            'quote' => $this->application->quotes()->where('status', 'sent')->latest('id')->first(),
            'deliverableKinds' => DeliverableKind::cases(),
            'canUploadDeliverable' => auth()->user()?->can('upload', [DeliverableDocument::class, $this->application]) ?? false,
            'canUploadInvoice' => $canUploadInvoice,
            'dealershipUsers' => $dealershipUsers,
            'stockControllerId' => $stockControllerId,
        ]);
    }
}
