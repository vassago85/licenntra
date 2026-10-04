<?php

namespace App\Models;

use Database\Factories\FleetVehicleEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetVehicleEvent extends Model
{
    /** @use HasFactory<FleetVehicleEventFactory> */
    use HasFactory;

    protected $fillable = [
        'fleet_vehicle_id', 'user_id', 'action', 'summary', 'context',
    ];

    public function fleetVehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }
}
