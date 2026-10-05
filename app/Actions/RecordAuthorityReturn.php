<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that the paperwork the department approved is physically back
 * in the office, then moves the application to Ready for collection.
 */
class RecordAuthorityReturn
{
    public function __construct(
        private TransitionApplication $transition,
        private RecordAudit $audit,
    ) {}

    public function handle(Application $application, User $actor, Carbon $receivedAt, ?string $notes = null): Application
    {
        if (! $actor->is_active || ! $actor->hasAnyRole(['reviewer', 'owner'])) {
            throw ValidationException::withMessages([
                'returned_at' => 'Only operations can record returned documents.',
            ]);
        }

        if ($application->stage !== ApplicationStage::Approved) {
            throw ValidationException::withMessages([
                'returned_at' => 'Only approved applications can be recorded as returned.',
            ]);
        }

        if ($receivedAt->isFuture()) {
            throw ValidationException::withMessages([
                'returned_at' => 'The receipt date cannot be in the future.',
            ]);
        }

        if ($application->authority_submitted_at !== null && $receivedAt->lt($application->authority_submitted_at)) {
            throw ValidationException::withMessages([
                'returned_at' => 'The receipt date cannot be before the submission date.',
            ]);
        }

        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        return DB::transaction(function () use ($application, $actor, $receivedAt, $notes): Application {
            $application->forceFill([
                'authority_returned_at' => $receivedAt,
                'authority_returned_by_id' => $actor->id,
                'authority_return_notes' => $notes,
            ])->save();

            $this->audit->handle(
                $actor,
                $application,
                'application.authority_returned',
                'Documents physically received back from the department on '.$receivedAt->format('d M Y H:i').'.',
                null,
                ['authority_returned_at' => $receivedAt->toIso8601String(), 'notes' => $notes],
            );

            return $this->transition->handle($application, ApplicationStage::ReadyForCollection, $actor);
        });
    }
}
