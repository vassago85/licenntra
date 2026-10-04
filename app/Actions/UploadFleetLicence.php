<?php

namespace App\Actions;

use App\Enums\FleetVehicleOcrStatus;
use App\Enums\VehicleCategory;
use App\Jobs\ReadFleetLicence;
use App\Models\ClientAccount;
use App\Models\DocumentVersion;
use App\Models\FleetVehicle;
use App\Models\FleetVehicleDocument;
use App\Models\FleetVehicleEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Store an uploaded licence file against a fleet account, create a pending
 * {@see FleetVehicle} + {@see FleetVehicleDocument}, and queue the OCR job.
 */
class UploadFleetLicence
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(ClientAccount $fleet, UploadedFile $file, User $actor, ?FleetVehicle $attachTo = null): FleetVehicleDocument
    {
        $this->guardFile($file);
        $this->guardAttachTo($fleet, $attachTo);

        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: $file->getMimeType();
        $size = $file->getSize() ?: filesize($path);
        $hash = hash_file('sha256', $path);
        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };

        $storedName = Str::uuid()->toString().'.'.$extension;
        $directory = 'fleet-vehicles/'.$fleet->id;
        $stored = $file->storeAs($directory, $storedName, 'documents');

        if ($stored === false) {
            throw ValidationException::withMessages(['upload' => 'The file could not be stored.']);
        }

        $record = DB::transaction(function () use ($fleet, $actor, $file, $mime, $size, $hash, $stored, $attachTo): FleetVehicleDocument {
            $version = DocumentVersion::query()->create([
                'storage_path' => $stored,
                'original_filename' => $file->getClientOriginalName(),
                'mime' => $mime,
                'size' => $size,
                'sha256' => $hash,
                'scan_status' => 'skipped',
                'inspection_status' => 'skipped',
                'uploaded_by' => $actor->id,
            ]);

            $vehicle = $attachTo ?? FleetVehicle::query()->create([
                'client_account_id' => $fleet->id,
                'vehicle_category' => VehicleCategory::Commercial->value,
            ]);

            $document = FleetVehicleDocument::query()->create([
                'fleet_vehicle_id' => $vehicle->id,
                'document_version_id' => $version->id,
                'ocr_status' => FleetVehicleOcrStatus::Pending->value,
                'uploaded_by_id' => $actor->id,
            ]);

            FleetVehicleEvent::query()->create([
                'fleet_vehicle_id' => $vehicle->id,
                'user_id' => $actor->id,
                'action' => $attachTo ? 'document_added' : 'created',
                'summary' => $attachTo
                    ? 'Additional licence document uploaded.'
                    : 'Vehicle created from uploaded licence.',
                'context' => ['document_id' => $document->id, 'original_filename' => $file->getClientOriginalName()],
            ]);

            return $document;
        });

        $this->audit->handle(
            $actor,
            $record->fleetVehicle,
            'fleet_vehicle.document.uploaded',
            'Fleet licence uploaded; OCR queued.',
            null,
            ['document_id' => $record->id, 'sha256' => $hash, 'mime' => $mime, 'size' => $size],
        );

        ReadFleetLicence::dispatch($record->id);

        return $record;
    }

    private function guardFile(UploadedFile $file): void
    {
        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            throw ValidationException::withMessages(['upload' => 'The file could not be read.']);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! is_string($mime) || ! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['upload' => 'Upload a PDF, JPG, or PNG.']);
        }

        $size = $file->getSize() ?: filesize($path);

        if ($size === false || $size > 15 * 1024 * 1024) {
            throw ValidationException::withMessages(['upload' => 'The file must be 15 MB or smaller.']);
        }
    }

    private function guardAttachTo(ClientAccount $fleet, ?FleetVehicle $attachTo): void
    {
        if ($attachTo === null) {
            return;
        }

        if ($attachTo->client_account_id !== $fleet->id) {
            throw ValidationException::withMessages(['upload' => 'That vehicle is on a different fleet.']);
        }
    }
}
