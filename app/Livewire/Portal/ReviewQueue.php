<?php

namespace App\Livewire\Portal;

use App\Enums\ApplicationStage;
use App\Models\Application;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.portal')]
class ReviewQueue extends Component
{
    use WithPagination;

    public string $stage = '';

    public bool $slaRisk = false;

    public string $assignment = '';

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Application::class);

        if (auth()->user()?->isClient()) {
            abort(403);
        }
    }

    public function updatedStage(): void
    {
        $this->resetPage();
    }

    public function updatedAssignment(): void
    {
        $this->resetPage();
    }

    public function updatedSlaRisk(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $rows = Application::query()
            ->with(['vehicle', 'clientAccount', 'reviewer'])
            ->where('stage', '!=', ApplicationStage::Draft)
            ->when($this->stage !== '', fn ($query) => $query->where('stage', $this->stage))
            ->when($this->assignment === 'me', fn ($query) => $query->where('assigned_reviewer_id', auth()->id()))
            ->when($this->assignment === 'unassigned', fn ($query) => $query->whereNull('assigned_reviewer_id'))
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where('reference', 'like', $term);
            })
            ->latest('updated_at')
            ->get()
            ->when($this->slaRisk, fn ($collection) => $collection->filter(fn (Application $application): bool => $application->slaFlag() !== null));

        return view('livewire.portal.review-queue', [
            'rows' => $rows,
            'stages' => ApplicationStage::cases(),
            'stats' => $this->stats(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        $active = Application::query()
            ->where('stage', '!=', ApplicationStage::Draft)
            ->whereNotIn('stage', [
                ApplicationStage::Completed,
                ApplicationStage::Cancelled,
                ApplicationStage::Archived,
            ]);

        return [
            'awaiting_review' => (clone $active)
                ->whereIn('stage', [ApplicationStage::Submitted, ApplicationStage::DocumentReview])
                ->count(),
            'with_client' => (clone $active)
                ->where('stage', ApplicationStage::ChangesRequested)
                ->count(),
            'assigned_to_me' => (clone $active)
                ->where('assigned_reviewer_id', auth()->id())
                ->count(),
            'sla_at_risk' => (clone $active)
                ->get()
                ->filter(fn (Application $a): bool => $a->slaFlag() !== null)
                ->count(),
        ];
    }
}
