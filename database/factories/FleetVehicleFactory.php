<?php

namespace Database\Factories;

use App\Enums\VehicleCategory;
use App\Models\ClientAccount;
use App\Models\FleetVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetVehicle>
 */
class FleetVehicleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_account_id' => ClientAccount::factory()->state(['type' => 'fleet_operator']),
            'vehicle_register_number' => strtoupper($this->faker->bothify('???###?')),
            'vin' => strtoupper($this->faker->bothify('???#######?#####')),
            'make' => $this->faker->randomElement(['Isuzu', 'Mercedes-Benz', 'Volvo', 'MAN', 'Hino']),
            'model' => $this->faker->bothify('F-###'),
            'vehicle_category' => VehicleCategory::Commercial->value,
            'licence_expires_on' => now()->addMonths(2)->endOfMonth()->toDateString(),
            'licence_expiry_source' => 'typed',
            'retired_at' => null,
        ];
    }

    public function passenger(): self
    {
        return $this->state(['vehicle_category' => VehicleCategory::Passenger->value]);
    }

    public function retired(): self
    {
        return $this->state(['retired_at' => now()]);
    }

    public function pending(): self
    {
        return $this->state([
            'licence_expires_on' => null,
            'licence_expiry_source' => null,
        ]);
    }

    public function expiresIn(string $month): self
    {
        return $this->state(['licence_expires_on' => $month]);
    }
}
