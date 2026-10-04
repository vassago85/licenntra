<?php

namespace App\Models;

use App\Enums\BodyDescription;
use App\Enums\DriveType;
use App\Enums\EconomicSector;
use App\Enums\FuelType;
use App\Enums\MainColour;
use App\Enums\NatureOfOwnership;
use App\Enums\OdometerType;
use App\Enums\ReasonForRegistration;
use App\Enums\SteeringPosition;
use App\Enums\Transmission;
use App\Enums\VehicleUsage;
use App\Models\Concerns\ScopesThroughApplication;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, ScopesThroughApplication;

    protected $fillable = [
        'application_id',

        // Core identity (already existed).
        'vin', 'vehicle_register_number', 'engine_number', 'make', 'model',
        'year', 'body_type', 'tare_kg', 'gvm_kg', 'vehicle_class',

        // Powertrain.
        'fuel_type', 'transmission', 'net_power_kw', 'engine_capacity_cc',

        // Visual + physical.
        'main_colour', 'colour_other', 'no_of_wheels',

        // Body / drive / use.
        'body_description', 'body_description_other',
        'drive_type',
        'vehicle_usage', 'vehicle_usage_other',
        'economic_sector', 'economic_sector_other',

        // Odometer + steering.
        'odometer_reading', 'odometer_type', 'steering_position',

        // Ownership + registration lifecycle.
        'nature_of_ownership', 'reason_for_registration',
        'used_on_public_road', 'date_liable', 'address_where_kept',

        // NaTIS identifiers.
        'natis_model_number',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * The NaTIS Vehicle Number is the SA-standard label for what the DB
     * column stores. Use this accessor in form views and preview output
     * so humans see the government terminology.
     */
    public function natisVehicleNumber(): ?string
    {
        return $this->vehicle_register_number;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'transmission' => Transmission::class,
            'main_colour' => MainColour::class,
            'body_description' => BodyDescription::class,
            'drive_type' => DriveType::class,
            'vehicle_usage' => VehicleUsage::class,
            'economic_sector' => EconomicSector::class,
            'odometer_type' => OdometerType::class,
            'steering_position' => SteeringPosition::class,
            'nature_of_ownership' => NatureOfOwnership::class,
            'reason_for_registration' => ReasonForRegistration::class,
            'used_on_public_road' => 'boolean',
            'date_liable' => 'date',
        ];
    }
}
