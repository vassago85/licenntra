<?php

namespace App\Livewire\Portal\Admin;

use App\Actions\ApproveFeeTableVersion;
use App\Livewire\Portal\Admin\Concerns\RequiresConfigurator;
use App\Models\FeeTable;
use App\Models\FeeTableVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Fee table versions follow a four-eyes workflow: one configurator drafts,
 * a different one approves. Approval supersedes the previous live version.
 */
#[Layout('layouts.portal')]
class FeeTableVersions extends Component
{
    use RequiresConfigurator;
    use WithPagination;

    public const STATUSES = [
        'draft' => 'Draft',
        'active' => 'Live',
        'superseded' => 'Superseded',
    ];

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'table', except: '')]
    public string $tableFilter = '';

    public bool $showForm = false;

    public string $feeTableId = '';

    public bool $copyLiveLines = true;

    public string $effectiveFrom = '';

    public string $notes = '';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function updating(string $property): void
    {
        if (in_array($property, ['statusFilter', 'tableFilter'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->feeTableId = $this->tableFilter;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    /**
     * Start a new draft, optionally seeded with every line from the
     * table's live version so a price change is an edit, not a retype.
     */
    public function createDraft(): void
    {
        $this->clearMessages();

        $data = $this->validate([
            'feeTableId' => ['required', Rule::exists('fee_tables', 'id')],
            'copyLiveLines' => ['boolean'],
            'effectiveFrom' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $table = FeeTable::query()->findOrFail((int) $data['feeTableId']);

        $draft = DB::transaction(function () use ($table, $data): FeeTableVersion {
            $draft = $table->versions()->create([
                'version' => ((int) $table->versions()->max('version')) + 1,
                'status' => 'draft',
                'created_by' => $this->configurator()->id,
                'effective_from' => $data['effectiveFrom'] ?: null,
                'notes' => $data['notes'] ?: null,
            ]);

            $live = $table->versions()->where('status', 'active')->latest('version')->first();

            if ($data['copyLiveLines'] && $live !== null) {
                foreach ($live->lines()->orderBy('sort_order')->orderBy('id')->get() as $line) {
                    $draft->lines()->create($line->only($line->getFillable()));
                }
            }

            return $draft;
        });

        $this->redirectRoute('admin.fee-table-versions.edit', $draft);
    }

    public function approve(int $versionId, ApproveFeeTableVersion $approve): void
    {
        $this->clearMessages();
        $version = FeeTableVersion::query()->with('feeTable')->findOrFail($versionId);

        if (! $version->isEditable()) {
            $this->errorMessage = 'Only draft versions can be approved.';

            return;
        }

        try {
            $approve->handle($version, $this->configurator());
        } catch (ValidationException $exception) {
            $this->errorMessage = $exception->validator->errors()->first();

            return;
        }

        $this->statusMessage = $version->feeTable?->name.' v'.$version->version.' is now live.';
    }

    public function delete(int $versionId): void
    {
        $this->clearMessages();
        $version = FeeTableVersion::query()->with('feeTable')->findOrFail($versionId);

        if (! $version->isEditable()) {
            $this->errorMessage = 'Live and superseded versions are kept for the record and cannot be deleted.';

            return;
        }

        $version->delete();
        $this->statusMessage = 'Draft '.$version->feeTable?->name.' v'.$version->version.' deleted.';
    }

    public function render(): View
    {
        $versions = FeeTableVersion::query()
            ->with(['feeTable', 'creator', 'approver'])
            ->withCount('lines')
            ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->when($this->tableFilter !== '', fn (Builder $query) => $query->where('fee_table_id', $this->tableFilter))
            ->orderByRaw("case status when 'draft' then 0 when 'active' then 1 else 2 end")
            ->latest('id')
            ->paginate(25);

        return view('livewire.portal.admin.fee-table-versions', [
            'versions' => $versions,
            'tables' => FeeTable::query()->orderBy('province')->orderBy('name')->get(),
            'statuses' => self::STATUSES,
            'currentUserId' => $this->configurator()->id,
        ]);
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->feeTableId = '';
        $this->copyLiveLines = true;
        $this->effectiveFrom = '';
        $this->notes = '';
        $this->resetErrorBag();
    }

    private function clearMessages(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
    }
}
