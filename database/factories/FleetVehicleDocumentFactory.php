<?php

namespace Database\Factories;

use App\Models\DocumentVersion;
use App\Models\FleetVehicle;
use App\Models\FleetVehicleDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetVehicleDocument>
 */
class FleetVehicleDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fleet_vehicle_id' => FleetVehicle::factory(),
            'document_version_id' => DocumentVersion::factory(),
            'ocr_status' => 'pending',
            'ocr_notes' => null,
            'ocr_expiry_candidate' => null,
            'ocr_register_candidate' => null,
            'ocr_vin_candidate' => null,
            'confirmed_at' => null,
            'confirmed_by_id' => null,
            'uploaded_by_id' => null,
        ];
    }

    public function confirmed(): self
    {
        return $this->state([
            'ocr_status' => 'clean',
            'confirmed_at' => now(),
        ]);
    }
}
