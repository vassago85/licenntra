<?php

namespace App\Livewire\Portal;

use App\Enums\HandoverDirection;
use App\Enums\HandoverStatus;
use App\Models\DocumentHandover;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.portal')]
class DocumentHandoverIndex extends Component
{
    use WithPagination;

    #[Url(as: 'direction')]
    public string $directionFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public function updatingDirectionFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->directionFilter = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorize('viewAny', DocumentHandover::class);

        $query = DocumentHandover::query()
            ->with(['createdBy', 'confirmedBy', 'clientAccount'])
            ->withCount('applications')
            ->latest('id');

        if ($this->directionFilter !== '') {
            $query->where('direction', $this->directionFilter);
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return view('livewire.portal.document-handover-index', [
            'handovers' => $query->paginate(20),
            'directions' => HandoverDirection::cases(),
            'statuses' => HandoverStatus::cases(),
        ]);
    }
}
