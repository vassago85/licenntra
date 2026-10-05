<?php

namespace App\Actions;

use App\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves the per-step warning times and re-dates every open application in
 * a changed step, so a new warning time applies to work already waiting
 * instead of only to the next stage change.
 */
class ApplyStageWarningTimes
{
    public function __construct(
        private RecordAudit $audit,
    ) {}

    /**
     * @param  array<string, int|null>  $hoursByStage  Stage value => hours, null switches the warning off.
     */
    public function handle(array $hoursByStage, User $actor): void
    {
        $settings = SystemSetting::current();

        $previous = [];
        foreach (ApplicationStage::warningStages() as $stage) {
            $previous[$stage->value] = $settings->warningHoursFor($stage);
        }

        $next = $previous;
        foreach ($hoursByStage as $stageValue => $hours) {
            if (array_key_exists($stageValue, $next)) {
                $next[$stageValue] = $hours !== null && $hours > 0 ? $hours : null;
            }
        }

        $changed = array_keys(array_filter(
            $next,
            fn (?int $hours, string $stageValue): bool => $previous[$stageValue] !== $hours,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed === []) {
            return;
        }

        DB::transaction(function () use ($settings, $actor, $previous, $next, $changed): void {
            $settings->update(['stage_warning_hours' => $next]);

            Application::query()
                ->whereIn('stage', $changed)
                ->withMax('stageHistories as stage_entered_at', 'created_at')
                ->chunkById(200, function ($applications) use ($next): void {
                    foreach ($applications as $application) {
                        $hours = $next[$application->stage->value];

                        // Base query so the queue's "last updated" order is left alone.
                        Application::query()->whereKey($application->id)->toBase()->update([
                            'due_at' => $hours === null
                                ? null
                                : $application->enteredStageAt()->copy()->addHours($hours),
                        ]);
                    }
                });

            $this->audit->handle(
                $actor,
                $settings,
                'settings.stage_warning_hours_changed',
                'Warning times changed for '.implode(', ', array_map(
                    fn (string $stageValue): string => ApplicationStage::from($stageValue)->label(),
                    $changed,
                )).'.',
                array_intersect_key($previous, array_flip($changed)),
                array_intersect_key($next, array_flip($changed)),
            );
        });
    }
}
