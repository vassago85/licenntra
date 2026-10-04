<?php

namespace Database\Factories;

use App\Models\FleetVehicle;
use App\Models\FleetVehicleEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetVehicleEvent>
 */
class FleetVehicleEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fleet_vehicle_id' => FleetVehicle::factory(),
            'user_id' => null,
            'action' => 'created',
            'summary' => 'Vehicle created',
            'context' => null,
        ];
    }
}
