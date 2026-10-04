<?php

namespace App\Actions;

use App\Models\FleetVehicle;
use App\Models\FleetVehicleEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RetireFleetVehicle
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(FleetVehicle $vehicle, User $actor): FleetVehicle
    {
        if ($vehicle->retired_at !== null) {
            return $vehicle;
        }

        DB::transaction(function () use ($vehicle, $actor): void {
            $vehicle->update(['retired_at' => now()]);

            FleetVehicleEvent::query()->create([
                'fleet_vehicle_id' => $vehicle->id,
                'user_id' => $actor->id,
                'action' => 'retired',
                'summary' => 'Vehicle retired from the fleet.',
            ]);
        });

        $this->audit->handle(
            $actor,
            $vehicle->refresh(),
            'fleet_vehicle.retired',
            'Fleet vehicle retired.',
        );

        return $vehicle;
    }
}
