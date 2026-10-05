<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubmitApplication
{
    public function handle(Application $application, User $actor): Application
    {
        return DB::transaction(function () use ($application, $actor): Application {
            $transition = app(TransitionApplication::class);
            $application = $transition->handle($application, ApplicationStage::Submitted, $actor);
            $application = $transition->handle($application, ApplicationStage::DocumentReview, null, isSystem: true);

            return app(AutoAssignPrimaryReviewer::class)->handle($application, $actor);
        });
    }
}
