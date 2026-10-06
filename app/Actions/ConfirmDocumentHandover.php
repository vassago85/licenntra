<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Enums\HandoverDirection;
use App\Enums\HandoverStatus;
use App\Models\Application;
use App\Models\DocumentHandover;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmDocumentHandover
{
    public function __construct(
        private RecordAudit $audit,
        private TransitionApplication $transitions,
    ) {}

    public function handle(User $actor, DocumentHandover $handover): DocumentHandover
    {
        if (! $handover->isPending()) {
            throw ValidationException::withMessages([
                'handover' => 'This hand-over has already been confirmed.',
            ]);
        }

        if ($handover->applications()->count() === 0) {
            throw ValidationException::withMessages([
                'handover' => 'Add at least one application before confirming the hand-over.',
            ]);
        }

        if (trim((string) $handover->counterparty_name) === '') {
            throw ValidationException::withMessages([
                'counterparty_name' => 'Capture the name of the person signing on behalf of the licensing company first.',
            ]);
        }

        if (trim((string) $handover->dealer_person_name) === '') {
            throw ValidationException::withMessages([
                'dealer_person_name' => 'Capture the name of the dealership staff member on the counter first.',
            ]);
        }

        return DB::transaction(function () use ($actor, $handover): DocumentHandover {
            $handover->forceFill([
                'status' => HandoverStatus::Completed,
                'confirmed_by_id' => $actor->id,
                'confirmed_at' => now(),
            ])->save();

            $completed = $handover->direction === HandoverDirection::Delivery
                ? $this->completeReturnedApplications($actor, $handover)
                : [];

            $this->audit->handle(
                $actor,
                $handover,
                'handover.confirmed',
                'Hand-over confirmed on-screen by both parties.',
                null,
                [
                    'direction' => $handover->direction->value,
                    'application_count' => $handover->applications()->count(),
                    'completed_application_ids' => $completed,
                ],
            );

            return $handover->refresh();
        });
    }

    /**
     * Returned paperwork handed back to the dealership closes the application.
     *
     * @return list<int>
     */
    private function completeReturnedApplications(User $actor, DocumentHandover $handover): array
    {
        $completed = [];

        $handover->applications()
            ->where('stage', ApplicationStage::ReadyForCollection->value)
            ->get()
            ->each(function (Application $application) use ($actor, $handover, &$completed): void {
                $this->transitions->handle(
                    $application,
                    ApplicationStage::Completed,
                    $actor,
                    'Handed back to the dealership on hand-over #'.$handover->id.'.',
                    isSystem: true,
                );
                $completed[] = $application->id;
            });

        return $completed;
    }
}
