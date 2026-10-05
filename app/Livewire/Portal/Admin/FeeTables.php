<?php

namespace App\Livewire\Portal\Admin;

use App\Enums\Province;
use App\Livewire\Portal\Admin\Concerns\RequiresConfigurator;
use App\Models\FeeTable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One fee table per province (or schedule). Prices live on its versions.
 */
#[Layout('layouts.portal')]
class FeeTables extends Component
{
    use RequiresConfigurator;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $province = '';

    public string $name = '';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $feeTableId): void
    {
        $table = FeeTable::query()->findOrFail($feeTableId);

        $this->resetForm();
        $this->editingId = $table->id;
        $this->province = $table->province?->value ?? '';
        $this->name = (string) $table->name;
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
            'province' => ['required', Rule::enum(Province::class), Rule::unique('fee_tables', 'province')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $table = $this->editingId === null ? new FeeTable : FeeTable::query()->findOrFail($this->editingId);
        $table->fill($data)->save();

        $this->statusMessage = $table->name.($this->editingId === null ? ' created.' : ' updated.');
        $this->resetForm();
    }

    public function delete(int $feeTableId): void
    {
        $this->clearMessages();
        $table = FeeTable::query()->withCount('versions')->findOrFail($feeTableId);

        if ($table->versions_count > 0) {
            $this->errorMessage = $table->name.' has versions, so it cannot be deleted.';

            return;
        }

        $table->delete();
        $this->statusMessage = $table->name.' deleted.';
    }

    public function render(): View
    {
        $tables = FeeTable::query()
            ->withCount('versions')
            ->with(['versions' => fn ($query) => $query->whereIn('status', ['active', 'draft'])->withCount('lines')])
            ->orderBy('province')
            ->orderBy('name')
            ->get();

        return view('livewire.portal.admin.fee-tables', [
            'tables' => $tables,
            'provinces' => Province::cases(),
        ]);
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->province = '';
        $this->name = '';
        $this->resetErrorBag();
    }

    private function clearMessages(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
    }
}
