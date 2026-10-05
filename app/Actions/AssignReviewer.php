<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AssignReviewer
{
    public function __construct(private RecordAudit $audit) {}

    public function handle(Application $application, User $actor, ?User $reviewer): Application
    {
        if ($reviewer !== null && ! $reviewer->hasAnyRole(['reviewer', 'owner'])) {
            throw ValidationException::withMessages([
                'reviewer' => 'Choose an operations team member.',
            ]);
        }

        $before = $application->assigned_reviewer_id;
        $application->assigned_reviewer_id = $reviewer?->id;
        $application->save();

        $primaryReviewer = $application->clientAccount?->primaryReviewer;
        $coveringFor = null;

        // The dealership has a primary reviewer AND someone else is
        // picking the application up - record whose shift is being
        // covered. Keeps a clean trail when the main reviewer is on
        // leave or sick and another reviewer takes over.
        if ($reviewer !== null
            && $primaryReviewer !== null
            && $primaryReviewer->id !== $reviewer->id) {
            $coveringFor = $primaryReviewer;
        }

        $summary = match (true) {
            $reviewer === null => 'Operations unassigned.',
            $coveringFor !== null => sprintf(
                'Operations assigned (%s, covering for %s).',
                $reviewer->name,
                $coveringFor->name,
            ),
            default => 'Operations assigned.',
        };

        $this->audit->handle(
            $actor,
            $application,
            'application.reviewer_assigned',
            $summary,
            ['assigned_reviewer_id' => $before],
            [
                'assigned_reviewer_id' => $reviewer?->id,
                'covering_for_user_id' => $coveringFor?->id,
            ],
        );

        return $application->refresh();
    }
}
