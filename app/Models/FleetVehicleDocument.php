<?php

namespace App\Models;

use App\Enums\FleetVehicleOcrStatus;
use Database\Factories\FleetVehicleDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetVehicleDocument extends Model
{
    /** @use HasFactory<FleetVehicleDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'fleet_vehicle_id', 'document_version_id', 'ocr_status', 'ocr_notes',
        'ocr_expiry_candidate', 'ocr_register_candidate', 'ocr_vin_candidate',
        'confirmed_at', 'confirmed_by_id', 'uploaded_by_id',
    ];

    public function fleetVehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class);
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function needsReview(): bool
    {
        return $this->confirmed_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocr_status' => FleetVehicleOcrStatus::class,
            'ocr_expiry_candidate' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }
}
