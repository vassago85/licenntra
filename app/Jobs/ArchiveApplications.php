<?php

namespace App\Jobs;

use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\SystemSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ArchiveApplications implements ShouldQueue
{
    use Queueable;

    public function handle(TransitionApplication $transition): void
    {
        $cutoff = now()->subDays(SystemSetting::current()->archive_after_days);

        Application::query()
            ->whereIn('stage', [ApplicationStage::Completed, ApplicationStage::Cancelled])
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->each(function (Application $application) use ($transition): void {
                try {
                    $transition->handle($application, ApplicationStage::Archived, null, isSystem: true);
                } catch (InvalidTransition) {
                    // Leave the application where it is and continue the batch.
                }
            });
    }
}
