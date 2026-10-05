<?php

namespace App\Livewire\Portal;

use App\Actions\CaptureRetentionConsent;
use App\Actions\StoreBusinessClientDocument;
use App\Models\BusinessClient;
use App\Models\DocumentType;
use App\Models\SystemSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.portal')]
class BusinessClientShow extends Component
{
    use WithFileUploads;

    public BusinessClient $businessClient;

    public bool $consent = false;

    public int $period_months = 12;

    /**
     * Document type code the dealer picked for the next upload. Mapped
     * to the type id server-side so a tampered form post can't attach
     * an arbitrary id.
     */
    public string $uploadTypeCode = '';

    public ?TemporaryUploadedFile $uploadFile = null;

    /**
     * Replacement file inputs keyed by business_client_document_id so
     * each existing row can carry its own picker without clashing.
     *
     * @var array<int, TemporaryUploadedFile|null>
     */
    public array $replacementFiles = [];

    public function mount(BusinessClient $businessClient): void
    {
        $this->authorize('view', $businessClient);
        $this->businessClient = $businessClient;
        $options = SystemSetting::current()->retention_period_options ?? [12];
        $this->period_months = (int) ($businessClient->retention_period_months ?: ($options[0] ?? 12));
    }

    public function saveConsent(): void
    {
        $this->authorize('update', $this->businessClient);

        try {
            $this->businessClient = app(CaptureRetentionConsent::class)->handle(
                $this->businessClient,
                auth()->user(),
                $this->period_months,
                $this->consent,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->consent = false;
        session()->flash('status', 'Retention consent saved.');
    }

    /**
     * Attach a new document to this business client. If a document of
     * the chosen type already exists, treat it as a replacement: a new
     * DocumentVersion is written and the shell's current_version_id
     * flips to point at the newest - old versions stay queryable for
     * audit.
     */
    public function uploadDocument(): void
    {
        $this->authorize('update', $this->businessClient);

        if ($this->uploadTypeCode === '') {
            $this->addError('uploadFile', 'Pick a document type first.');

            return;
        }

        if (! $this->uploadFile instanceof TemporaryUploadedFile) {
            $this->addError('uploadFile', 'Choose a PDF, JPG, or PNG.');

            return;
        }

        $type = $this->resolveType($this->uploadTypeCode);

        if ($type === null) {
            $this->addError('uploadFile', 'That document type is not available for business clients.');

            return;
        }

        try {
            app(StoreBusinessClientDocument::class)->handle(
                $this->businessClient,
                $type,
                $this->uploadFile,
                auth()->user(),
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->uploadFile = null;
        $this->uploadTypeCode = '';
        session()->flash('status', $type->name.' saved.');
    }

    /**
     * In-place replace an already-attached document. The dealer picks a
     * fresh file next to the existing row and we upload a new version
     * for the same shell record.
     */
    public function replaceDocument(int $businessClientDocumentId): void
    {
        $this->authorize('update', $this->businessClient);

        $document = $this->businessClient->documents()
            ->with('documentType')
            ->findOrFail($businessClientDocumentId);

        $file = $this->replacementFiles[$businessClientDocumentId] ?? null;

        if (! $file instanceof TemporaryUploadedFile) {
            $this->addError('replacementFiles.'.$businessClientDocumentId, 'Choose a PDF, JPG, or PNG.');

            return;
        }

        try {
            app(StoreBusinessClientDocument::class)->handle(
                $this->businessClient,
                $document->documentType,
                $file,
                auth()->user(),
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        unset($this->replacementFiles[$businessClientDocumentId]);
        session()->flash('status', $document->documentType->name.' updated.');
    }

    private function resolveType(string $code): ?DocumentType
    {
        $allowed = $this->availableDocumentTypeCodes();

        if (! in_array($code, $allowed, true)) {
            return null;
        }

        return DocumentType::query()->where('code', $code)->first();
    }

    /**
     * The curated set of document types that make sense on a business
     * client record - identity, address, and supporting paperwork. The
     * vehicle-specific types (CoF, weighbridge, original NaTIS) live on
     * applications instead.
     *
     * @return list<string>
     */
    private function availableDocumentTypeCodes(): array
    {
        return ['brn_certificate', 'proxy_id', 'id_copy', 'poa', 'supporting_documents'];
    }

    public function render(): View
    {
        $settings = SystemSetting::current();
        $codes = $this->availableDocumentTypeCodes();

        $availableTypes = DocumentType::query()
            ->whereIn('code', $codes)
            ->get()
            ->sortBy(fn (DocumentType $type) => array_search($type->code, $codes, true))
            ->values();

        $documents = $this->businessClient->documents()
            ->with(['documentType', 'currentVersion.uploader'])
            ->get()
            ->sortBy(function ($document) use ($codes) {
                $index = array_search($document->documentType?->code, $codes, true);

                return $index === false ? PHP_INT_MAX : $index;
            })
            ->values();

        return view('livewire.portal.business-client-show', [
            'settings' => $settings,
            'options' => $settings->retention_period_options ?? [],
            'consents' => $this->businessClient->consents()->latest('id')->get(),
            'availableTypes' => $availableTypes,
            'documents' => $documents,
        ]);
    }
}
