<?php

namespace App\Livewire\Portal;

use App\Actions\ConfirmDocumentHandover;
use App\Actions\SaveDocumentHandover;
use App\Enums\HandoverDirection;
use App\Models\Application;
use App\Models\DocumentHandover;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.portal')]
class DocumentHandoverForm extends Component
{
    use WithFileUploads;

    public ?DocumentHandover $handover = null;

    #[Url(as: 'direction')]
    public string $direction = '';

    public string $counterparty_name = '';

    public string $counterparty_identifier = '';

    public string $counterparty_company = 'Licensing authority';

    public string $dealer_person_name = '';

    public string $items_summary = '';

    public string $notes = '';

    /** @var list<int> */
    public array $application_ids = [];

    /** @var array<int, string> */
    public array $line_items = [];

    public ?TemporaryUploadedFile $signedScan = null;

    public function mount(?DocumentHandover $handover = null): void
    {
        if ($handover === null || $handover->id === null) {
            $this->authorize('create', DocumentHandover::class);
            $this->dealer_person_name = (string) auth()->user()?->name;

            return;
        }

        $this->authorize('view', $handover);
        $this->handover = $handover->load('applications');
        $this->fillFromHandover();
    }

    private function fillFromHandover(): void
    {
        $handover = $this->handover;

        if ($handover === null) {
            return;
        }

        $this->direction = $handover->direction->value;
        $this->counterparty_name = (string) $handover->counterparty_name;
        $this->counterparty_identifier = (string) $handover->counterparty_identifier;
        $this->counterparty_company = (string) ($handover->counterparty_company ?? 'Licensing authority');
        $this->dealer_person_name = (string) ($handover->dealer_person_name ?? auth()->user()?->name);
        $this->items_summary = (string) $handover->items_summary;
        $this->notes = (string) $handover->notes;
        $this->application_ids = $handover->applications->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($handover->applications as $application) {
            $this->line_items[$application->id] = (string) ($application->pivot->item_description ?? '');
        }
    }

    public function save(bool $flash = true): void
    {
        $this->persist($flash);
    }

    private function persist(bool $flash): void
    {
        $user = auth()->user();

        if ($this->handover === null) {
            $this->authorize('create', DocumentHandover::class);
        } else {
            $this->authorize('update', $this->handover);
        }

        try {
            $saved = app(SaveDocumentHandover::class)->handle($user, $this->payload(), $this->handover);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $creating = $this->handover === null;
        $this->handover = $saved->load('applications');

        if ($creating) {
            $this->redirectRoute('handovers.edit', $saved);

            return;
        }

        $this->fillFromHandover();

        if ($flash) {
            session()->flash('status', 'Hand-over saved.');
        }
    }

    public function confirm(): void
    {
        if ($this->handover === null) {
            $this->addError('handover', 'Save the hand-over first.');

            return;
        }

        $this->persist(false);
        $this->authorize('confirm', $this->handover);

        try {
            $this->handover = app(ConfirmDocumentHandover::class)->handle(auth()->user(), $this->handover);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        session()->flash('status', 'Hand-over confirmed. Both sides can now file the signed copy.');
    }

    public function uploadSigned(): void
    {
        if ($this->handover === null) {
            $this->addError('signedScan', 'Save the hand-over first.');

            return;
        }

        $this->authorize('uploadSigned', $this->handover);

        if (! $this->signedScan instanceof TemporaryUploadedFile) {
            $this->addError('signedScan', 'Choose a PDF, JPG, or PNG.');

            return;
        }

        $file = $this->signedScan;
        $realPath = $file->getRealPath() ?: '';
        $mime = $realPath !== '' ? (new \finfo(FILEINFO_MIME_TYPE))->file($realPath) : null;
        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];

        if (! is_string($mime) || ! isset($allowed[$mime])) {
            $this->addError('signedScan', 'Upload a PDF, JPG, or PNG.');

            return;
        }

        $size = $file->getSize() ?: 0;

        if ($size === 0 || $size > 15 * 1024 * 1024) {
            $this->addError('signedScan', 'The file must be 15 MB or smaller.');

            return;
        }

        $directory = 'handovers/'.$this->handover->id;
        $storedName = Str::uuid()->toString().'.'.$allowed[$mime];
        $stored = $file->storeAs($directory, $storedName, 'documents');

        if ($stored === false) {
            $this->addError('signedScan', 'The file could not be stored.');

            return;
        }

        $sha256 = hash_file('sha256', Storage::disk('documents')->path($stored)) ?: null;

        $this->handover->forceFill([
            'signed_file_path' => $stored,
            'signed_file_original_name' => $file->getClientOriginalName(),
            'signed_file_mime' => $mime,
            'signed_file_size' => $size,
            'signed_file_sha256' => $sha256,
            'signed_file_uploaded_at' => now(),
        ])->save();

        $this->signedScan = null;
        $this->handover->refresh();

        session()->flash('status', 'Signed hand-over scan attached.');
    }

    public function delete(): void
    {
        if ($this->handover === null) {
            return;
        }

        $this->authorize('delete', $this->handover);
        $this->handover->delete();
        $this->redirectRoute('handovers.index');
    }

    public function render(): View
    {
        $accountId = auth()->user()?->client_account_id;

        $availableApplications = Application::query()
            ->where('client_account_id', $accountId)
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'reference', 'stage']);

        return view('livewire.portal.document-handover-form', [
            'directions' => HandoverDirection::cases(),
            'availableApplications' => $availableApplications,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'direction' => $this->direction,
            'counterparty_name' => $this->counterparty_name,
            'counterparty_identifier' => $this->counterparty_identifier,
            'counterparty_company' => $this->counterparty_company,
            'dealer_person_name' => $this->dealer_person_name,
            'items_summary' => $this->items_summary,
            'notes' => $this->notes,
            'application_ids' => array_values(array_map('intval', $this->application_ids)),
            'line_items' => $this->line_items,
        ];
    }
}
