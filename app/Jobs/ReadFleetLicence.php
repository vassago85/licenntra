<?php

namespace App\Jobs;

use App\Enums\FleetVehicleOcrStatus;
use App\Models\FleetVehicleDocument;
use App\Models\FleetVehicleEvent;
use App\Services\LicenceOcr\LicenceOcrReader;
use App\Services\LicenceOcr\LicenceOcrResult;
use App\Services\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs the licence file through the OCR reader and parks the candidates on
 * the fleet vehicle document for a reviewer to confirm. Notifies the user
 * who uploaded the file once the read finishes.
 */
class ReadFleetLicence implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $fleetVehicleDocumentId) {}

    public function handle(LicenceOcrReader $reader, NotificationDispatcher $notifications): void
    {
        $document = FleetVehicleDocument::query()
            ->with(['documentVersion', 'uploadedBy', 'fleetVehicle'])
            ->find($this->fleetVehicleDocumentId);

        if ($document === null) {
            return;
        }

        $version = $document->documentVersion;

        if ($version === null) {
            $this->record($document, FleetVehicleOcrStatus::Failed, 'The stored file could not be located.');

            return;
        }

        $absolutePath = Storage::disk('documents')->path($version->storage_path);

        try {
            $result = $reader->read($absolutePath, $version->mime);
        } catch (Throwable $exception) {
            Log::warning('fleet_licence.ocr_failed', [
                'document_id' => $document->id,
                'exception' => $exception->getMessage(),
            ]);
            $result = LicenceOcrResult::failed(
                'OCR failed: '.mb_substr($exception->getMessage(), 0, 180),
            );
        }

        $status = FleetVehicleOcrStatus::tryFrom($result->status) ?? FleetVehicleOcrStatus::Failed;

        $document->update([
            'ocr_status' => $status->value,
            'ocr_notes' => $result->notes,
            'ocr_expiry_candidate' => $result->expiryDate,
            'ocr_register_candidate' => $result->registerNumber,
            'ocr_vin_candidate' => $result->vin,
        ]);

        FleetVehicleEvent::query()->create([
            'fleet_vehicle_id' => $document->fleet_vehicle_id,
            'user_id' => null,
            'action' => 'ocr_completed',
            'summary' => 'OCR finished with status '.$status->value.'.',
            'context' => [
                'document_id' => $document->id,
                'status' => $status->value,
                'expiry_candidate' => $result->expiryDate,
                'register_candidate' => $result->registerNumber,
                'vin_candidate' => $result->vin,
            ],
        ]);

        if ($document->uploadedBy !== null) {
            $notifications->fleetLicenceReady($document->refresh());
        }
    }

    private function record(FleetVehicleDocument $document, FleetVehicleOcrStatus $status, string $notes): void
    {
        $document->update([
            'ocr_status' => $status->value,
            'ocr_notes' => $notes,
        ]);
    }
}
