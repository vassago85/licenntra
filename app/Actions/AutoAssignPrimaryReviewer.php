<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\User;

/**
 * If the dealership has nominated a primary reviewer and the application
 * isn't already assigned, hand it straight to them so recurring customers
 * land on the right reviewer's queue without waiting in the general pool.
 *
 * Returns the application unchanged (or refreshed with the new reviewer).
 */
class AutoAssignPrimaryReviewer
{
    public function __construct(private AssignReviewer $assigner) {}

    public function handle(Application $application, User $actor): Application
    {
        if ($application->assigned_reviewer_id !== null) {
            return $application;
        }

        $primary = $application->clientAccount?->primaryReviewer;

        if ($primary === null || ! $primary->is_active) {
            return $application;
        }

        return $this->assigner->handle($application, $actor, $primary);
    }
}
