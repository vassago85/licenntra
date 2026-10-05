<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Audit log</h1>
            <p class="text-sm text-muted">Every mutation on the platform, in reverse chronological order. Read-only.</p>
        </div>
        <div class="text-xs text-muted">
            {{ number_format($events->total()) }} {{ $events->total() === 1 ? 'entry' : 'entries' }}
            @if ($search !== '' || $actionFilter !== '' || $actorRoleFilter !== '' || ! $includeSystem)
                <button wire:click="clearFilters" class="ml-2 font-medium text-ink hover:underline">Clear filters</button>
            @endif
        </div>
    </div>

    <section class="mb-4 rounded-md border border-line bg-white p-3">
        <div class="grid gap-3 md:grid-cols-4">
            <label class="block text-sm md:col-span-2">
                <span class="text-muted">Search</span>
                <input wire:model.live.debounce.300ms="search" type="search"
                    placeholder="Actor name, email or summary"
                    class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
            </label>
            <label class="block text-sm">
                <span class="text-muted">Action</span>
                <select wire:model.live="actionFilter"
                    class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <option value="">All actions</option>
                    @foreach ($actionOptions as $action)
                        <option value="{{ $action }}">{{ $action }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm">
                <span class="text-muted">Actor role</span>
                <select wire:model.live="actorRoleFilter"
                    class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <option value="">Any role</option>
                    @foreach ($roleOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <label class="mt-3 flex items-center gap-2 text-sm">
            <input wire:model.live="includeSystem" type="checkbox"
                class="h-4 w-4 rounded border-line text-[color:var(--brand)] focus:ring-[color:var(--brand)]">
            <span>Include system entries</span>
        </label>
    </section>

    <section class="overflow-hidden rounded-md border border-line bg-white">
        @if ($events->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-muted">No audit entries match the current filters.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th class="px-3 py-2 font-medium">When</th>
                            <th class="px-3 py-2 font-medium">Actor</th>
                            <th class="px-3 py-2 font-medium">Action</th>
                            <th class="px-3 py-2 font-medium">Subject</th>
                            <th class="px-3 py-2 font-medium">Summary</th>
                            <th class="hidden px-3 py-2 font-medium xl:table-cell">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($events as $event)
                            @php
                                /** @var \App\Models\AuditEvent $event */
                                $subject = null;
                                $subjectUrl = null;
                                $subjectLabel = 'Record #'.$event->subject_id;

                                if ($event->subject_type && class_exists($event->subject_type)) {
                                    $parts = explode('\\', $event->subject_type);
                                    $tail = end($parts) ?: 'Record';

                                    if ($event->subject_type === \App\Models\Application::class) {
                                        $app = \App\Models\Application::query()->find($event->subject_id);
                                        if ($app) {
                                            $subjectLabel = $app->reference;
                                            $subjectUrl = route('applications.show', $app);
                                        } else {
                                            $subjectLabel = 'Application #'.$event->subject_id.' (deleted)';
                                        }
                                    } else {
                                        $subjectLabel = $tail.' #'.$event->subject_id;
                                    }
                                }
                            @endphp
                            <tr class="{{ $event->is_system ? 'bg-paper/40' : '' }}">
                                <td class="whitespace-nowrap px-3 py-2 text-xs text-muted">
                                    {{ $event->occurred_at?->format('d M Y H:i') }}
                                </td>
                                <td class="px-3 py-2">
                                    <div class="font-medium">{{ $event->actor?->name ?? 'System' }}</div>
                                    @if ($event->actor_role)
                                        <div class="text-xs text-muted">{{ $roleOptions[$event->actor_role] ?? $event->actor_role }}</div>
                                    @elseif ($event->is_system)
                                        <div class="text-xs text-muted">Platform</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full bg-paper px-2 py-0.5 font-mono text-[11px] text-ink ring-1 ring-inset ring-line">
                                        {{ $event->action }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-muted">
                                    @if ($subjectUrl)
                                        <a href="{{ $subjectUrl }}" class="text-ink hover:underline">{{ $subjectLabel }}</a>
                                    @else
                                        {{ $subjectLabel }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <span title="{{ $event->summary }}">
                                        {{ \Illuminate\Support\Str::limit((string) $event->summary, 140) }}
                                    </span>
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-2 font-mono text-xs text-muted xl:table-cell">
                                    {{ $event->ip ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-line px-3 py-2">
                {{ $events->withQueryString()->links() }}
            </div>
        @endif
    </section>
</div>
