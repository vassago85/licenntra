<?php

namespace App\Actions;

use App\Enums\DatafixStatus;
use App\Enums\DocumentStatus;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Models\Application;

class SyncDatafixStatus
{
    public function handle(Application $application): void
    {
        $applies = $application->request_type === RequestType::DataChange
            || ($application->request_type === RequestType::NewRegistration && $application->vehicle_category === VehicleCategory::Commercial);

        if (! $applies) {
            $application->datafix_status = DatafixStatus::NotRequired;
            $application->save();

            return;
        }

        if (in_array($application->datafix_status, [DatafixStatus::InProgress, DatafixStatus::Completed, DatafixStatus::Queried], true)) {
            return;
        }

        $codes = ['weighbridge_certificate', 'cof', 'body_builder_certificate'];

        if ($application->request_type === RequestType::NewRegistration) {
            $codes[] = 'srf';
        }

        $documents = $application->documents()->with('documentType')->get();
        $ready = collect($codes)->every(function (string $code) use ($documents): bool {
            $document = $documents->first(fn ($row): bool => $row->documentType?->code === $code && $row->party_role === 'vehicle');

            if ($document === null) {
                return $code === 'srf';
            }

            if (! $document->required) {
                return true;
            }

            return $document->status === DocumentStatus::Accepted;
        });

        $application->datafix_status = $ready ? DatafixStatus::Ready : DatafixStatus::AwaitingDocuments;
        $application->save();
    }
}
