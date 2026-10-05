<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Services\OperationsWorkloadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record an application's hand-off to the licensing authority.
 *
 * Captures the authority-side reference and submission date, enforces the
 * same readiness rule the dashboard uses, stamps the submission pack that
 * was lodged (preparing one if nobody printed it) and transitions the
 * stage, all in one transaction.
 *
 * Never called from a dashboard count click. The dashboard surfaces the
 * action; a reviewer explicitly supplies reference and date in the form.
 */
class SubmitToAuthority
{
    public function __construct(
        private TransitionApplication $transition,
        private RecordAudit $audit,
        private OperationsWorkloadService $workload,
        private NotificationDispatcher $notifications,
        private PrepareSubmissionPack $packs,
    ) {}

    public function handle(Application $application, User $actor, string $reference, Carbon $submittedAt): Application
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw ValidationException::withMessages([
                'authority_reference' => 'Enter the authority reference captured on hand-off.',
            ]);
        }

        if ($submittedAt->isFuture()) {
            throw ValidationException::withMessages([
                'authority_submitted_at' => 'The submission date cannot be in the future.',
            ]);
        }

        if (! $this->workload->isReadyForAuthority($application)) {
            throw new InvalidTransition('This application is not yet ready for authority submission.');
        }

        return DB::transaction(function () use ($application, $actor, $reference, $submittedAt): Application {
            $before = [
                'authority_reference' => $application->authority_reference,
                'authority_submitted_at' => $application->authority_submitted_at?->toIso8601String(),
            ];

            $pack = $this->packs->handle($application, $actor);
            $pack->forceFill(['submitted_at' => $submittedAt, 'authority_reference' => $reference])->save();

            $application->authority_reference = $reference;
            $application->authority_submitted_at = $submittedAt;
            $application->save();

            $this->transition->handle($application, ApplicationStage::SubmittedToAuthority, $actor);

            $this->audit->handle(
                $actor,
                $application,
                'application.authority_submitted',
                'Submitted to authority as '.$reference.' with pack #'.$pack->id.'.',
                $before,
                [
                    'authority_reference' => $reference,
                    'authority_submitted_at' => $submittedAt->toIso8601String(),
                    'submission_pack_id' => $pack->id,
                ],
            );

            $this->notifications->authoritySubmitted($application->refresh());

            return $application->refresh();
        });
    }
}
