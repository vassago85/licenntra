<?php

namespace App\Livewire\Portal\Admin;

use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Enums\TaxTreatment;
use App\Livewire\Portal\Admin\Concerns\RequiresConfigurator;
use App\Models\FeeLine;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every fee line across all tables, for comparing prices between provinces
 * and making quick amount corrections on draft versions.
 */
#[Layout('layouts.portal')]
class FeeLines extends Component
{
    use RequiresConfigurator;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'province', except: '')]
    public string $provinceFilter = '';

    #[Url(as: 'status', except: 'active')]
    public string $statusFilter = 'active';

    #[Url(as: 'category', except: '')]
    public string $categoryFilter = '';

    #[Url(as: 'tax', except: '')]
    public string $taxFilter = '';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'provinceFilter', 'statusFilter', 'categoryFilter', 'taxFilter'], true)) {
            $this->resetPage();
        }
    }

    public function updateAmount(int $lineId, string $amountRand): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
        $line = $this->draftLine($lineId);

        if (! is_numeric($amountRand) || (float) $amountRand < 0) {
            $this->errorMessage = 'Enter an amount of R0.00 or more.';

            return;
        }

        $line->update(['amount_cents' => (int) round(((float) $amountRand) * 100)]);
        $this->statusMessage = $line->label.' set to R'.number_format($line->amount_cents / 100, 2).'.';
    }

    public function toggleVisible(int $lineId): void
    {
        $line = $this->draftLine($lineId);
        $line->update(['client_visible' => ! $line->client_visible]);
        $this->statusMessage = $line->label.($line->client_visible ? ' now shows on client quotes.' : ' is now hidden from client quotes.');
    }

    public function render(): View
    {
        $lines = FeeLine::query()
            ->select('fee_lines.*')
            ->join('fee_table_versions', 'fee_table_versions.id', '=', 'fee_lines.fee_table_version_id')
            ->join('fee_tables', 'fee_tables.id', '=', 'fee_table_versions.fee_table_id')
            ->with('version.feeTable')
            ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('fee_table_versions.status', $this->statusFilter))
            ->when($this->provinceFilter !== '', fn (Builder $query) => $query->where('fee_tables.province', $this->provinceFilter))
            ->when($this->categoryFilter === 'service', fn (Builder $query) => $query->whereNull('fee_lines.licence_category'))
            ->when(! in_array($this->categoryFilter, ['', 'service'], true), fn (Builder $query) => $query->where('fee_lines.licence_category', $this->categoryFilter))
            ->when($this->taxFilter !== '', fn (Builder $query) => $query->where('fee_lines.tax_treatment', $this->taxFilter))
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('fee_lines.label', 'like', $term)
                    ->orWhere('fee_lines.code', 'like', $term));
            })
            ->orderBy('fee_tables.province')
            ->orderByDesc('fee_table_versions.version')
            ->orderBy('fee_lines.sort_order')
            ->orderBy('fee_lines.id')
            ->paginate(50);

        return view('livewire.portal.admin.fee-lines', [
            'lines' => $lines,
            'provinces' => Province::cases(),
            'categories' => LicenceFeeCategory::cases(),
            'taxTreatments' => TaxTreatment::cases(),
            'statuses' => FeeTableVersions::STATUSES,
        ]);
    }

    private function draftLine(int $lineId): FeeLine
    {
        $line = FeeLine::query()->with('version')->findOrFail($lineId);
        abort_unless($line->version?->isEditable(), 403, 'Only lines on draft versions can be changed.');

        return $line;
    }
}
