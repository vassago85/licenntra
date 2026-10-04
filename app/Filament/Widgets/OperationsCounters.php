<?php

namespace App\Filament\Widgets;

use App\Services\OperationsWorkloadService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Four compact counters at the top of the operations workspace.
 *
 * Each counter is a link to the matching Outstanding tasks tab. Clicking
 * a counter never performs an action - it opens the filtered task list
 * where a reviewer can decide what to do.
 */
class OperationsCounters extends StatsOverviewWidget
{
    protected ?string $heading = 'Operations';

    protected ?string $description = 'Click a counter to open the matching Outstanding tasks list.';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -5;

    protected function getColumns(): int
    {
        return 4;
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $service = app(OperationsWorkloadService::class);

        return array_map(function (array $counter): Stat {
            $colour = match ($counter['tone']) {
                'danger' => $counter['count'] > 0 ? 'danger' : 'gray',
                'warning' => $counter['count'] > 0 ? 'warning' : 'gray',
                'info' => $counter['count'] > 0 ? 'info' : 'gray',
                default => 'gray',
            };

            return Stat::make($counter['label'], (string) $counter['count'])
                ->description($counter['description'])
                ->color($colour)
                ->url($counter['url']);
        }, $service->counters());
    }
}
