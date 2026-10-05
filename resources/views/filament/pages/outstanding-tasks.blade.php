<x-filament-panels::page>
    <div class="flex flex-col gap-4">

        {{-- KPI stat cards per workload bucket --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($tabLabels as $key => $label)
                @php
                    $count = $counts[$key] ?? 0;
                @endphp
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $key }}')"
                    class="rounded-md border {{ $tab === $key ? 'border-ink' : 'border-line' }} bg-surface p-3 text-left transition hover:border-ink"
                >
                    <p class="text-xs uppercase tracking-wide text-muted">{{ $label }}</p>
                    <p class="mt-1 text-xl font-semibold text-ink">{{ $count }}</p>
                </button>
            @endforeach
        </div>

        {{-- Filter bar --}}
        <section class="rounded-md border border-line bg-surface p-3">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
                <label class="md:col-span-2">
                    <span class="block text-xs text-muted">Search</span>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Reference, customer, submitting user, NaTIS no or VIN"
                        class="mt-1 block h-9 w-full rounded-md border border-line bg-surface px-2 text-sm"
                    >
                </label>
                <label>
                    <span class="block text-xs text-muted">Customer</span>
                    <select wire:model.live="account_id" class="mt-1 block h-9 w-full rounded-md border border-line bg-surface px-2 text-sm">
                        <option value="">Any customer</option>
                        @foreach ($accounts as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="block text-xs text-muted">Submitting user</span>
                    <select wire:model.live="submitted_by_id" class="mt-1 block h-9 w-full rounded-md border border-line bg-surface px-2 text-sm">
                        <option value="">Any submitting user</option>
                        @foreach ($submittingUsers as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="block text-xs text-muted">Assigned reviewer</span>
                    <select wire:model.live="reviewer_id" class="mt-1 block h-9 w-full rounded-md border border-line bg-surface px-2 text-sm">
                        <option value="">Any reviewer</option>
                        @foreach ($reviewers as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="block text-xs text-muted">Province / dept.</span>
                    <select wire:model.live="province" class="mt-1 block h-9 w-full rounded-md border border-line bg-surface px-2 text-sm">
                        <option value="">Any province</option>
                        @foreach ($provinces as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="md:col-span-6 flex items-end justify-between gap-3">
                    <label class="inline-flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model.live="overdue" class="rounded border-line">
                        Overdue only
                    </label>
                    {{-- View toggle: Cards vs Table --}}
                    <div class="inline-flex rounded-md border border-line bg-paper p-0.5 text-xs" role="group" aria-label="View mode">
                        @foreach (['table' => 'Table', 'cards' => 'Cards'] as $mode => $modeLabel)
                            @php
                                $active = $viewMode === $mode;
                            @endphp
                            <button
                                type="button"
                                wire:click="$set('viewMode', '{{ $mode }}')"
                                aria-pressed="{{ $active ? 'true' : 'false' }}"
                                class="rounded px-2.5 py-1 font-medium transition {{ $active ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink' }}"
                            >
                                {{ $modeLabel }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        @if ($viewMode === 'table')
            <section class="overflow-x-auto rounded-md border border-line bg-surface">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b border-line text-xs text-muted">
                        <tr>
                            <th class="px-3 py-2 font-medium">Customer</th>
                            <th class="hidden 2xl:table-cell px-3 py-2 font-medium">Submitted by</th>
                            <th class="px-3 py-2 font-medium">Application</th>
                            <th class="px-3 py-2 font-medium">Vehicle</th>
                            <th class="hidden xl:table-cell px-3 py-2 font-medium">Request type</th>
                            <th class="px-3 py-2 font-medium">Required action</th>
                            <th class="hidden lg:table-cell px-3 py-2 font-medium">Reviewer</th>
                            <th class="hidden xl:table-cell px-3 py-2 font-medium">Waiting since</th>
                            <th class="px-3 py-2 font-medium">Due</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tasks as $task)
                            <tr class="border-b border-line last:border-0 {{ $task['overdue'] ? 'bg-[color-mix(in_srgb,var(--color-danger)_6%,transparent)]' : '' }}">
                                <td class="whitespace-nowrap px-3 py-2 text-sm">
                                    <span class="font-medium text-ink">{{ $task['account']?->name ?? '-' }}</span>
                                    @if ($task['account']?->type)
                                        <span class="ml-1 text-xs text-muted">{{ $task['account']->type->label() }}</span>
                                    @endif
                                </td>
                                <td class="hidden 2xl:table-cell whitespace-nowrap px-3 py-2 text-xs text-ink">
                                    {{ $task['submitted_by']?->name ?? 'Unknown' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-ink">{{ $task['application']?->reference ?? '-' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs">
                                    @if ($task['vehicle_registration'])
                                        <span class="font-mono text-ink">{{ $task['vehicle_registration'] }}</span>
                                    @elseif ($task['vehicle_vin'])
                                        <span class="font-mono text-muted">VIN {{ \Illuminate\Support\Str::of($task['vehicle_vin'])->substr(-6) }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="hidden xl:table-cell whitespace-nowrap px-3 py-2 text-xs text-ink">
                                    {{ $task['application']?->request_type?->label() ?? '-' }}
                                </td>
                                <td class="px-3 py-2 text-sm">
                                    <div class="font-medium text-ink">{{ $task['label'] }}</div>
                                    @if ($task['blocker'])
                                        <div class="text-xs text-muted">{{ $task['blocker'] }}</div>
                                    @endif
                                </td>
                                <td class="hidden lg:table-cell whitespace-nowrap px-3 py-2 text-sm text-ink">{{ $task['reviewer']?->name ?? 'Unassigned' }}</td>
                                <td class="hidden xl:table-cell whitespace-nowrap px-3 py-2 text-xs text-muted">{{ $task['waiting_since']?->diffForHumans() ?? '-' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs">
                                    @if ($task['due_at'])
                                        <span class="{{ $task['overdue'] ? 'font-semibold text-[#9E2419]' : 'text-muted' }}">
                                            {{ $task['due_at']->format('d M H:i') }}
                                        </span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right text-sm">
                                    @php
                                        $allowed = match ($task['kind']) {
                                            'approval.document' => $canReviewDocuments,
                                            'approval.payment' => $canVerifyPayments,
                                            'ready_to_submit' => $canSubmitToAuthority,
                                            default => true,
                                        };
                                    @endphp
                                    @if (! $allowed)
                                        <span class="inline-flex items-center rounded-md border border-dashed border-line px-2.5 py-1 text-xs text-muted" title="Not permitted for your role">
                                            No access
                                        </span>
                                    @elseif ($task['kind'] === 'ready_to_submit')
                                        {{ ($this->submitToAuthorityAction)(['application' => $task['application']->id]) }}
                                    @else
                                        <a href="{{ $task['action_url'] }}" class="inline-flex items-center rounded-md border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink hover:bg-paper">
                                            {{ $task['action_label'] }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-3 py-8 text-center text-sm text-muted">
                                    Nothing in this view right now.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        @else
            @if ($tasks->isEmpty())
                <section class="rounded-md border border-dashed border-line bg-surface p-10 text-center text-sm text-muted">
                    Nothing in this view right now.
                </section>
            @else
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($tasks as $task)
                        @php
                            $allowed = match ($task['kind']) {
                                'approval.document' => $canReviewDocuments,
                                'approval.payment' => $canVerifyPayments,
                                'ready_to_submit' => $canSubmitToAuthority,
                                default => true,
                            };
                        @endphp
                        <article class="flex flex-col justify-between gap-3 rounded-md border {{ $task['overdue'] ? 'border-[#9E2419]' : 'border-line' }} bg-surface p-4">
                            <div class="flex flex-col gap-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        @if ($task['vehicle_registration'])
                                            <div class="font-mono text-xl font-bold tracking-wide text-ink">{{ $task['vehicle_registration'] }}</div>
                                        @elseif ($task['vehicle_vin'])
                                            <div class="font-mono text-sm text-ink">VIN {{ \Illuminate\Support\Str::of($task['vehicle_vin'])->substr(-6) }}</div>
                                        @else
                                            <div class="font-mono text-sm text-muted">{{ $task['application']?->reference ?? '-' }}</div>
                                        @endif
                                        <div class="truncate text-xs text-muted">
                                            {{ $task['account']?->name ?? '-' }}@if ($task['account']?->type) <span>· {{ $task['account']->type->label() }}</span>@endif
                                        </div>
                                        <div class="truncate text-xs text-muted">
                                            Submitted by {{ $task['submitted_by']?->name ?? 'Unknown' }}
                                        </div>
                                    </div>
                                    @if ($task['overdue'])
                                        <span class="inline-flex items-center rounded-full border border-[#9E2419] bg-[color-mix(in_srgb,var(--color-danger)_10%,transparent)] px-2 py-0.5 text-xs font-medium text-[#9E2419]">Overdue</span>
                                    @endif
                                </div>

                                <div class="rounded-md bg-paper px-3 py-2">
                                    <div class="text-sm font-medium text-ink">{{ $task['label'] }}</div>
                                    @if ($task['blocker'])
                                        <div class="mt-0.5 text-xs text-muted">{{ $task['blocker'] }}</div>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-muted">
                                    <span class="font-mono">{{ $task['application']?->reference ?? '-' }}</span>
                                    <span>{{ $task['application']?->request_type?->label() ?? '-' }}</span>
                                    <span>Reviewer: {{ $task['reviewer']?->name ?? 'Unassigned' }}</span>
                                    @if ($task['waiting_since'])
                                        <span>Since {{ $task['waiting_since']->diffForHumans() }}</span>
                                    @endif
                                    @if ($task['due_at'])
                                        <span class="{{ $task['overdue'] ? 'font-semibold text-[#9E2419]' : '' }}">Due {{ $task['due_at']->format('d M H:i') }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center justify-end border-t border-line pt-3">
                                @if (! $allowed)
                                    <span class="inline-flex items-center rounded-md border border-dashed border-line px-2.5 py-1 text-xs text-muted" title="Not permitted for your role">No access</span>
                                @elseif ($task['kind'] === 'ready_to_submit')
                                    {{ ($this->submitToAuthorityAction)(['application' => $task['application']->id]) }}
                                @else
                                    <a href="{{ $task['action_url'] }}" class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium text-white" style="background: var(--brand, var(--color-accent))">
                                        {{ $task['action_label'] }}
                                    </a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        @endif

        @if (method_exists($tasks, 'hasPages') && $tasks->hasPages())
            <div class="mt-2 flex justify-end">{{ $tasks->links() }}</div>
        @endif
    </div>
</x-filament-panels::page>
