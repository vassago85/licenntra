<?php

namespace App\Livewire\Portal\Admin;

use App\Livewire\Portal\Admin\Concerns\RequiresConfigurator;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Catalogue of document kinds clients can upload. Rules decide when each
 * one is required.
 */
#[Layout('layouts.portal')]
class DocumentTypes extends Component
{
    use RequiresConfigurator;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public bool $isIdentityDocument = false;

    public bool $requiresOriginal = false;

    public string $maxAgeDays = '';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $documentTypeId): void
    {
        $type = DocumentType::query()->findOrFail($documentTypeId);

        $this->resetForm();
        $this->editingId = $type->id;
        $this->code = (string) $type->code;
        $this->name = (string) $type->name;
        $this->isIdentityDocument = (bool) $type->is_identity_document;
        $this->requiresOriginal = (bool) $type->requires_original;
        $this->maxAgeDays = $type->max_age_days !== null ? (string) $type->max_age_days : '';
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->clearMessages();

        $data = $this->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('document_types', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:120'],
            'isIdentityDocument' => ['boolean'],
            'requiresOriginal' => ['boolean'],
            'maxAgeDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ], [
            'code.regex' => 'Use lowercase letters, numbers and underscores only.',
        ]);

        $type = $this->editingId === null ? new DocumentType : DocumentType::query()->findOrFail($this->editingId);
        $type->fill([
            'code' => $data['code'],
            'name' => $data['name'],
            'is_identity_document' => (bool) $data['isIdentityDocument'],
            'requires_original' => (bool) $data['requiresOriginal'],
            'max_age_days' => filled($data['maxAgeDays']) ? (int) $data['maxAgeDays'] : null,
        ])->save();

        $this->statusMessage = $type->name.($this->editingId === null ? ' added.' : ' updated.');
        $this->resetForm();
    }

    /**
     * Types referenced by rules or uploaded documents stay - removing them
     * would orphan history.
     */
    public function delete(int $documentTypeId): void
    {
        $this->clearMessages();
        $type = DocumentType::query()->withCount('rules')->findOrFail($documentTypeId);

        $isUsed = $type->rules_count > 0
            || ApplicationDocument::query()->where('document_type_id', $type->id)->exists();

        if ($isUsed) {
            $this->errorMessage = $type->name.' is used by document rules or uploaded documents, so it cannot be deleted.';

            return;
        }

        $type->delete();
        $this->statusMessage = $type->name.' deleted.';
    }

    public function render(): View
    {
        return view('livewire.portal.admin.document-types', [
            'types' => DocumentType::query()->withCount('rules')->orderBy('name')->get(),
        ]);
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->code = '';
        $this->name = '';
        $this->isIdentityDocument = false;
        $this->requiresOriginal = false;
        $this->maxAgeDays = '';
        $this->resetErrorBag();
    }

    private function clearMessages(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
    }
}
