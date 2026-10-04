<?php

namespace App\Models;

use App\Enums\LicenceExpirySource;
use App\Enums\VehicleCategory;
use App\Models\Concerns\ScopesToClientAccount;
use Database\Factories\FleetVehicleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetVehicle extends Model
{
    /** @use HasFactory<FleetVehicleFactory> */
    use HasFactory, ScopesToClientAccount;

    protected $fillable = [
        'client_account_id', 'vehicle_register_number', 'vin', 'make', 'model',
        'vehicle_category', 'licence_expires_on', 'licence_expiry_source', 'retired_at',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FleetVehicleDocument::class)->orderByDesc('id');
    }

    public function latestConfirmedDocument(): HasMany
    {
        return $this->documents()->whereNotNull('confirmed_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FleetVehicleEvent::class)->orderByDesc('id');
    }

    public function isConfirmed(): bool
    {
        return $this->licence_expires_on !== null
            || $this->documents()->whereNotNull('confirmed_at')->exists();
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * Vehicles ready to appear on the fleet list. A vehicle is only visible to
     * the fleet once the licensing company confirms the uploaded licence.
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereHas('documents', fn (Builder $q): Builder => $q->whereNotNull('confirmed_at'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_category' => VehicleCategory::class,
            'licence_expiry_source' => LicenceExpirySource::class,
            'licence_expires_on' => 'date',
            'retired_at' => 'datetime',
        ];
    }
}
