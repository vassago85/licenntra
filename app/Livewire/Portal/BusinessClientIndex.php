<?php

namespace App\Livewire\Portal;

use App\Models\BusinessClient;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.portal')]
class BusinessClientIndex extends Component
{
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', BusinessClient::class);
    }

    public function render(): View
    {
        return view('livewire.portal.business-client-index', [
            'clients' => BusinessClient::query()
                ->when($this->search !== '', fn ($query) => $query->where('business_name', 'like', '%'.$this->search.'%'))
                ->orderBy('business_name')
                ->get(),
        ]);
    }
}
