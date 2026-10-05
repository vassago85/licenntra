<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        {{-- Filter bar --}}
        <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
                <div class="md:col-span-2">
                    <label for="task-search" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Search</label>
                    <input
                        id="task-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Reference, customer, submitting user, NaTIS no or VIN"
                        class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    >
                </div>
                <div>
                    <label for="task-account" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Customer</label>
                    <select id="task-account" wire:model.live="account_id" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Any customer</option>
                        @foreach ($accounts as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="task-submitted-by" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Submitting user</label>
                    <select id="task-submitted-by" wire:model.live="submitted_by_id" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Any submitting user</option>
                        @foreach ($submittingUsers as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="task-reviewer" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Assigned reviewer</label>
                    <select id="task-reviewer" wire:model.live="reviewer_id" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Any reviewer</option>
                        @foreach ($reviewers as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="task-province" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Province / dept.</label>
                    <select id="task-province" wire:model.live="province" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Any province</option>
                        @foreach ($provinces as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-6 flex items-end justify-between gap-3">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model.live="overdue" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                        Overdue only
                    </label>
                    @include('filament.pages._partials.view-toggle', ['view' => $viewMode, 'wireModel' => 'viewMode'])
                </div>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="flex flex-wrap items-center gap-1 border-b border-gray-200 dark:border-gray-700">
            @foreach ($tabLabels as $key => $label)
                @php
                    $isActive = $tab === $key;
                    $count = $counts[$key] ?? 0;
                @endphp
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $key }}')"
                    class="relative -mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm transition {{ $isActive ? 'border-primary-500 font-semibold text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}"
                >
                    <span>{{ $label }}</span>
                    @if ($count > 0)
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-mono text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $count }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        @if ($viewMode === 'table')
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Customer</th>
                            <th class="hidden 2xl:table-cell px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Submitted by</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Application</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Vehicle</th>
                            <th class="hidden xl:table-cell px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Request type</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Required action</th>
                            <th class="hidden lg:table-cell px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Reviewer</th>
                            <th class="hidden xl:table-cell px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Waiting since</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Due</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                        @forelse ($tasks as $task)
                            <tr class="{{ $task['overdue'] ? 'bg-red-50/60 dark:bg-red-900/10' : '' }} hover:bg-gray-50 dark:hover:bg-gray-800">
                                <td class="whitespace-nowrap px-3 py-2 text-sm">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $task['account']?->name ?? '-' }}</span>
                                    @if ($task['account']?->type)
                                        <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">{{ $task['account']->type->label() }}</span>
                                    @endif
                                </td>
                                <td class="hidden 2xl:table-cell whitespace-nowrap px-3 py-2 text-xs text-gray-700 dark:text-gray-200">
                                    {{ $task['submitted_by']?->name ?? 'Unknown' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-200">{{ $task['application']?->reference ?? '-' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs">
                                    @if ($task['vehicle_registration'])
                                        <span class="font-mono text-gray-700 dark:text-gray-200">{{ $task['vehicle_registration'] }}</span>
                                    @elseif ($task['vehicle_vin'])
                                        <span class="font-mono text-gray-500 dark:text-gray-400">VIN {{ \Illuminate\Support\Str::of($task['vehicle_vin'])->substr(-6) }}</span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="hidden xl:table-cell whitespace-nowrap px-3 py-2 text-xs text-gray-700 dark:text-gray-200">
                                    {{ $task['application']?->request_type?->label() ?? '-' }}
                                </td>
                                <td class="px-3 py-2 text-sm">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $task['label'] }}</div>
                                    @if ($task['blocker'])
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $task['blocker'] }}</div>
                                    @endif
                                </td>
                                <td class="hidden lg:table-cell whitespace-nowrap px-3 py-2 text-sm text-gray-700 dark:text-gray-200">{{ $task['reviewer']?->name ?? 'Unassigned' }}</td>
                                <td class="hidden xl:table-cell whitespace-nowrap px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ $task['waiting_since']?->diffForHumans() ?? '-' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs">
                                    @if ($task['due_at'])
                                        <span class="{{ $task['overdue'] ? 'font-semibold text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">
                                            {{ $task['due_at']->format('d M H:i') }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">-</span>
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
                                        <span class="inline-flex items-center rounded-md border border-dashed border-gray-200 px-2.5 py-1 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500" title="Not permitted for your role">
                                            No access
                                        </span>
                                    @elseif ($task['kind'] === 'ready_to_submit')
                                        {{ ($this->submitToAuthorityAction)(['application' => $task['application']->id]) }}
                                    @else
                                        <a href="{{ $task['action_url'] }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                            {{ $task['action_label'] }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Nothing in this view right now.
                                </td>
                            </tr>
                        @endforelse
                        {{-- colspan=10 is safe: hidden-but-present cells still count for colspan, and the empty row only renders when there are no visible rows anyway. --}}
                    </tbody>
                </table>
            </div>
        @else
            @if ($tasks->isEmpty())
                <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                    Nothing in this view right now.
                </div>
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
                        <div class="flex flex-col justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 {{ $task['overdue'] ? 'ring-1 ring-red-300 dark:ring-red-700' : '' }}">
                            <div class="flex flex-col gap-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        @if ($task['vehicle_registration'])
                                            <div class="font-mono text-xl font-bold tracking-wide text-gray-900 dark:text-gray-100">{{ $task['vehicle_registration'] }}</div>
                                        @elseif ($task['vehicle_vin'])
                                            <div class="font-mono text-sm text-gray-700 dark:text-gray-200">VIN {{ \Illuminate\Support\Str::of($task['vehicle_vin'])->substr(-6) }}</div>
                                        @else
                                            <div class="font-mono text-sm text-gray-500 dark:text-gray-400">{{ $task['application']?->reference ?? '-' }}</div>
                                        @endif
                                        <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                            {{ $task['account']?->name ?? '-' }}@if ($task['account']?->type) <span>· {{ $task['account']->type->label() }}</span>@endif
                                        </div>
                                        <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                            Submitted by {{ $task['submitted_by']?->name ?? 'Unknown' }}
                                        </div>
                                    </div>
                                    @if ($task['overdue'])
                                        <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-500/10 dark:text-red-200 dark:ring-red-500/30">Overdue</span>
                                    @endif
                                </div>

                                <div class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-800">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $task['label'] }}</div>
                                    @if ($task['blocker'])
                                        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $task['blocker'] }}</div>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="font-mono">{{ $task['application']?->reference ?? '-' }}</span>
                                    <span>{{ $task['application']?->request_type?->label() ?? '-' }}</span>
                                    <span>Reviewer: {{ $task['reviewer']?->name ?? 'Unassigned' }}</span>
                                    @if ($task['waiting_since'])
                                        <span>Since {{ $task['waiting_since']->diffForHumans() }}</span>
                                    @endif
                                    @if ($task['due_at'])
                                        <span class="{{ $task['overdue'] ? 'font-semibold text-red-600 dark:text-red-400' : '' }}">Due {{ $task['due_at']->format('d M H:i') }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center justify-end border-t border-gray-100 pt-3 dark:border-gray-800">
                                @if (! $allowed)
                                    <span class="inline-flex items-center rounded-md border border-dashed border-gray-200 px-2.5 py-1 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500" title="Not permitted for your role">No access</span>
                                @elseif ($task['kind'] === 'ready_to_submit')
                                    {{ ($this->submitToAuthorityAction)(['application' => $task['application']->id]) }}
                                @else
                                    <a href="{{ $task['action_url'] }}" class="inline-flex items-center rounded-md bg-primary-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-500">
                                        {{ $task['action_label'] }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif

        @if ($tasks->hasPages())
            <div>{{ $tasks->links() }}</div>
        @endif
    </div>
</x-filament-panels::page>
