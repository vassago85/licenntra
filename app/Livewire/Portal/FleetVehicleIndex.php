<?php

namespace App\Livewire\Portal;

use App\Actions\RetireFleetVehicle;
use App\Models\FleetVehicle;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.portal')]
class FleetVehicleIndex extends Component
{
    public bool $showRetired = false;

    public function mount(): void
    {
        $this->authorize('viewAny', FleetVehicle::class);
    }

    public function retire(int $vehicleId): void
    {
        $vehicle = FleetVehicle::query()->findOrFail($vehicleId);
        $this->authorize('retire', $vehicle);

        app(RetireFleetVehicle::class)->handle($vehicle, auth()->user());
    }

    public function render(): View
    {
        $base = FleetVehicle::query()
            ->with(['documents.documentVersion'])
            ->confirmed();

        $active = (clone $base)
            ->active()
            ->orderBy('licence_expires_on')
            ->orderBy('vehicle_register_number')
            ->get();

        $retired = $this->showRetired
            ? (clone $base)
                ->whereNotNull('retired_at')
                ->orderByDesc('retired_at')
                ->get()
            : collect();

        return view('livewire.portal.fleet-vehicle-index', [
            'grouped' => $this->groupByMonth($active),
            'retired' => $retired,
            'activeCount' => $active->count(),
            'retiredCount' => FleetVehicle::query()->confirmed()->whereNotNull('retired_at')->count(),
        ]);
    }

    /**
     * @param  Collection<int, FleetVehicle>  $vehicles
     * @return Collection<string, Collection<int, FleetVehicle>>
     */
    private function groupByMonth(Collection $vehicles): Collection
    {
        $today = Carbon::today()->startOfMonth();

        return $vehicles->groupBy(function (FleetVehicle $vehicle) use ($today): string {
            $expiry = $vehicle->licence_expires_on;

            if ($expiry === null) {
                return 'No expiry on file';
            }

            if ($expiry->copy()->startOfMonth()->lessThan($today)) {
                return 'Overdue';
            }

            return $expiry->format('F Y');
        });
    }
}
