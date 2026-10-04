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
        if ($reviewer !== null && ! $reviewer->hasAnyRole(['reviewer', 'customer_admin', 'super_admin'])) {
            throw ValidationException::withMessages([
                'reviewer' => 'Choose a reviewer.',
            ]);
        }

        $before = $application->assigned_reviewer_id;
        $application->assigned_reviewer_id = $reviewer?->id;
        $application->save();

        $this->audit->handle(
            $actor,
            $application,
            'application.reviewer_assigned',
            $reviewer === null ? 'Reviewer unassigned.' : 'Reviewer assigned.',
            ['assigned_reviewer_id' => $before],
            ['assigned_reviewer_id' => $reviewer?->id],
        );

        return $application->refresh();
    }
}
