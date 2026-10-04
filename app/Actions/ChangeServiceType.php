<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Enums\ServiceType;
use App\Models\Application;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ChangeServiceType
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Application $application, User $actor, ServiceType $serviceType, string $reason): Application
    {
        $reason = trim($reason);

        if ($application->stage === ApplicationStage::Draft || $application->stage === ApplicationStage::Archived) {
            throw ValidationException::withMessages([
                'service_type' => 'Service type can be changed after submission.',
            ]);
        }

        if (! $actor->hasAnyRole(['reviewer', 'customer_admin', 'super_admin'])) {
            throw ValidationException::withMessages([
                'service_type' => 'Only a reviewer can change the service type.',
            ]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required.',
            ]);
        }

        $before = $application->service_type?->value;
        $application->service_type = $serviceType;
        $application->fee_snapshot = app(CalculateFees::class)->snapshot($application);
        $application->save();

        $this->audit->handle(
            $actor,
            $application,
            'application.service_type_changed',
            'Service type changed after submission.',
            ['service_type' => $before],
            ['service_type' => $serviceType->value, 'reason' => $reason],
        );

        return $application->refresh();
    }
}
