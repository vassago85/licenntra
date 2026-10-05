<?php

namespace App\Actions;

use App\Enums\LicenceExpirySource;
use App\Enums\VehicleCategory;
use App\Models\FleetVehicle;
use App\Models\FleetVehicleDocument;
use App\Models\FleetVehicleEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save the licensing-company reviewer's confirmation. Takes the fields they
 * see on the confirmation screen (which started as OCR hints), validates them,
 * and makes the vehicle visible to the fleet.
 *
 * @phpstan-type ConfirmPayload array{
 *     vehicle_register_number: string|null,
 *     vin: string|null,
 *     make: string|null,
 *     model: string|null,
 *     vehicle_category: string,
 *     licence_expires_on: string|null,
 * }
 */
class ConfirmFleetVehicle
{
    public function __construct(private RecordAudit $audit) {}

    /**
     * @param  ConfirmPayload  $payload
     */
    public function handle(FleetVehicleDocument $document, User $reviewer, array $payload): FleetVehicle
    {
        $vehicle = $document->fleetVehicle;

        if ($vehicle === null) {
            throw ValidationException::withMessages(['vehicle' => 'The vehicle for this document could not be found.']);
        }

        $category = VehicleCategory::tryFrom((string) ($payload['vehicle_category'] ?? ''));

        if ($category === null) {
            throw ValidationException::withMessages(['vehicle_category' => 'Pick passenger or commercial.']);
        }

        $expiry = $this->parseDate($payload['licence_expires_on'] ?? null);
        $registerNumber = $this->trimOrNull($payload['vehicle_register_number'] ?? null);
        $vin = $this->trimOrNull($payload['vin'] ?? null);

        $this->guardRegisterUniqueness($vehicle, $registerNumber);

        $source = $this->resolveSource($document, $expiry, $registerNumber, $vin);

        $before = [
            'licence_expires_on' => $vehicle->licence_expires_on?->toDateString(),
            'vehicle_register_number' => $vehicle->vehicle_register_number,
            'vin' => $vehicle->vin,
            'vehicle_category' => $vehicle->vehicle_category?->value,
        ];

        $after = DB::transaction(function () use ($vehicle, $document, $reviewer, $category, $expiry, $registerNumber, $vin, $source, $payload): array {
            $vehicle->update([
                'vehicle_register_number' => $registerNumber,
                'vin' => $vin,
                'make' => $this->trimOrNull($payload['make'] ?? null),
                'model' => $this->trimOrNull($payload['model'] ?? null),
                'vehicle_category' => $category->value,
                'licence_expires_on' => $expiry?->toDateString(),
                'licence_expiry_source' => $expiry !== null ? $source->value : null,
            ]);

            $document->update([
                'confirmed_at' => now(),
                'confirmed_by_id' => $reviewer->id,
            ]);

            FleetVehicleEvent::query()->create([
                'fleet_vehicle_id' => $vehicle->id,
                'user_id' => $reviewer->id,
                'action' => 'confirmed',
                'summary' => 'Operations confirmed vehicle and licence expiry.',
                'context' => [
                    'document_id' => $document->id,
                    'licence_expires_on' => $expiry?->toDateString(),
                    'licence_expiry_source' => $expiry !== null ? $source->value : null,
                ],
            ]);

            return [
                'licence_expires_on' => $expiry?->toDateString(),
                'vehicle_register_number' => $registerNumber,
                'vin' => $vin,
                'vehicle_category' => $category->value,
            ];
        });

        $this->audit->handle(
            $reviewer,
            $vehicle->refresh(),
            'fleet_vehicle.confirmed',
            'Operations confirmed fleet vehicle.',
            $before,
            $after,
        );

        return $vehicle;
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['licence_expires_on' => 'Use the format YYYY-MM-DD.']);
        }
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function guardRegisterUniqueness(FleetVehicle $vehicle, ?string $registerNumber): void
    {
        if ($registerNumber === null) {
            return;
        }

        $exists = FleetVehicle::query()
            ->withoutGlobalScopes()
            ->where('client_account_id', $vehicle->client_account_id)
            ->where('vehicle_register_number', $registerNumber)
            ->where('id', '!=', $vehicle->id)
            ->whereNull('retired_at')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'vehicle_register_number' => 'This fleet already has a vehicle with register number '.$registerNumber.'. Attach the file to that vehicle instead.',
            ]);
        }
    }

    private function resolveSource(FleetVehicleDocument $document, ?Carbon $expiry, ?string $registerNumber, ?string $vin): LicenceExpirySource
    {
        if ($expiry === null) {
            return LicenceExpirySource::Typed;
        }

        $candidate = $document->ocr_expiry_candidate?->toDateString();

        if ($candidate !== null && $candidate === $expiry->toDateString()) {
            return LicenceExpirySource::Scan;
        }

        return LicenceExpirySource::Typed;
    }
}
