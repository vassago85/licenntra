<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Outstanding tasks</h1>
            <p class="text-sm text-muted">Cross-dealership backlog: approvals, submission packs, awaiting return, ready for handover.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ session('status') }}</p>
    @endif

    {{-- KPI stat cards per workload bucket --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($tabLabels as $key => $label)
            @php $count = $counts[$key] ?? 0; @endphp
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                class="rounded-md border {{ $tab === $key ? 'border-ink ring-1 ring-ink/10' : 'border-line' }} bg-white p-3 text-left transition hover:border-ink">
                <p class="text-xs uppercase tracking-wide text-muted">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold">{{ $count }}</p>
            </button>
        @endforeach
    </div>

    {{-- Filter bar --}}
    <section class="mb-4 rounded-md border border-line bg-white p-3">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
            <label class="md:col-span-2">
                <span class="block text-xs text-muted">Search</span>
                <input type="search" wire:model.live.debounce.300ms="search"
                    placeholder="Reference, customer, submitting user, NaTIS no or VIN"
                    class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
            </label>
            <label>
                <span class="block text-xs text-muted">Customer</span>
                <select wire:model.live="accountId" class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                    <option value="">Any customer</option>
                    @foreach ($accounts as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="block text-xs text-muted">Submitting user</span>
                <select wire:model.live="submittedById" class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                    <option value="">Any submitting user</option>
                    @foreach ($submittingUsers as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="block text-xs text-muted">Assigned reviewer</span>
                <select wire:model.live="reviewerId" class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                    <option value="">Any reviewer</option>
                    @foreach ($reviewers as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="block text-xs text-muted">Province / dept.</span>
                <select wire:model.live="province" class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                    <option value="">Any province</option>
                    @foreach ($provinces as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <div class="md:col-span-6 flex items-end justify-between gap-3">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="overdue" class="rounded border-line">
                    Overdue only
                </label>
                <div class="inline-flex rounded-md border border-line bg-paper p-0.5 text-xs" role="group" aria-label="View mode">
                    @foreach (['table' => 'Table', 'cards' => 'Cards'] as $mode => $modeLabel)
                        @php $active = $viewMode === $mode; @endphp
                        <button type="button" wire:click="$set('viewMode', '{{ $mode }}')"
                            aria-pressed="{{ $active ? 'true' : 'false' }}"
                            class="rounded px-2.5 py-1 font-medium transition {{ $active ? 'bg-white text-ink shadow-sm ring-1 ring-line' : 'text-muted hover:text-ink' }}">
                            {{ $modeLabel }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    @if ($viewMode === 'table')
        <section class="overflow-x-auto rounded-md border border-line bg-white">
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
                        @php
                            $allowed = match ($task['kind']) {
                                'approval.document' => $canReviewDocuments,
                                'approval.payment' => $canVerifyPayments,
                                'ready_to_submit' => $canSubmitToAuthority,
                                default => true,
                            };
                        @endphp
                        <tr class="border-b border-line last:border-0 {{ $task['overdue'] ? 'bg-red-50/60' : '' }}">
                            <td class="whitespace-nowrap px-3 py-2 text-sm">
                                <span class="font-medium">{{ $task['account']?->name ?? '-' }}</span>
                                @if ($task['account']?->type)
                                    <span class="ml-1 text-xs text-muted">{{ $task['account']->type->label() }}</span>
                                @endif
                            </td>
                            <td class="hidden 2xl:table-cell whitespace-nowrap px-3 py-2 text-xs">
                                {{ $task['submitted_by']?->name ?? 'Unknown' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $task['application']?->reference ?? '-' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs">
                                @if ($task['vehicle_registration'])
                                    <span class="font-mono">{{ $task['vehicle_registration'] }}</span>
                                @elseif ($task['vehicle_vin'])
                                    <span class="font-mono text-muted">VIN {{ \Illuminate\Support\Str::of($task['vehicle_vin'])->substr(-6) }}</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="hidden xl:table-cell whitespace-nowrap px-3 py-2 text-xs">
                                {{ $task['application']?->request_type?->label() ?? '-' }}
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <div class="font-medium">{{ $task['label'] }}</div>
                                @if ($task['blocker'])
                                    <div class="text-xs text-muted">{{ $task['blocker'] }}</div>
                                @endif
                            </td>
                            <td class="hidden lg:table-cell whitespace-nowrap px-3 py-2 text-sm">{{ $task['reviewer']?->name ?? 'Unassigned' }}</td>
                            <td class="hidden xl:table-cell whitespace-nowrap px-3 py-2 text-xs text-muted">{{ $task['waiting_since']?->diffForHumans() ?? '-' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs">
                                @if ($task['due_at'])
                                    <span class="{{ $task['overdue'] ? 'font-semibold text-red-800' : 'text-muted' }}">
                                        {{ $task['due_at']->format('d M H:i') }}
                                    </span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right text-sm">
                                @if ($allowed && $task['kind'] === 'ready_to_submit')
                                    <button type="button" wire:click="openSubmitModal({{ $task['application']->id }})"
                                        class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium text-white"
                                        style="background: var(--brand);">
                                        Submit to authority
                                    </button>
                                @elseif ($allowed)
                                    <a href="{{ $task['action_url'] }}"
                                        class="inline-flex items-center rounded-md border border-line bg-white px-2.5 py-1 text-xs font-medium hover:bg-paper">
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
            <section class="rounded-md border border-dashed border-line bg-white p-10 text-center text-sm text-muted">
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
                    <article class="flex flex-col justify-between gap-3 rounded-md border {{ $task['overdue'] ? 'border-red-300 ring-1 ring-red-200' : 'border-line' }} bg-white p-4">
                        <div class="flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    @if ($task['vehicle_registration'])
                                        <div class="font-mono text-xl font-bold tracking-wide">{{ $task['vehicle_registration'] }}</div>
                                    @elseif ($task['vehicle_vin'])
                                        <div class="font-mono text-sm">VIN {{ \Illuminate\Support\Str::of($task['vehicle_vin'])->substr(-6) }}</div>
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
                                    <span class="inline-flex items-center rounded-full border border-red-300 bg-red-50 px-2 py-0.5 text-xs font-medium text-red-800">Overdue</span>
                                @endif
                            </div>

                            <div class="rounded-md bg-paper px-3 py-2">
                                <div class="text-sm font-medium">{{ $task['label'] }}</div>
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
                                    <span class="{{ $task['overdue'] ? 'font-semibold text-red-800' : '' }}">Due {{ $task['due_at']->format('d M H:i') }}</span>
                                @endif
                            </div>
                        </div>

                        @if ($allowed)
                            <div class="flex items-center justify-end border-t border-line pt-3">
                                @if ($task['kind'] === 'ready_to_submit')
                                    <button type="button" wire:click="openSubmitModal({{ $task['application']->id }})"
                                        class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium text-white"
                                        style="background: var(--brand);">
                                        Submit to authority
                                    </button>
                                @else
                                    <a href="{{ $task['action_url'] }}"
                                        class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium text-white"
                                        style="background: var(--brand);">
                                        {{ $task['action_label'] }}
                                    </a>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    @endif

    @if (method_exists($tasks, 'hasPages') && $tasks->hasPages())
        <div class="mt-3 flex justify-end">{{ $tasks->links() }}</div>
    @endif

    {{-- Submit-to-authority modal --}}
    @if ($submitApplicationId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            x-data
            @keydown.escape.window="$wire.cancelSubmitModal()">
            <div class="w-full max-w-md rounded-md border border-line bg-white p-5 shadow-lg">
                <h2 class="text-base font-semibold">Submit to authority</h2>
                <p class="mt-1 text-xs text-muted">Capture the authority reference and the submission date. The application will move to &ldquo;At the authority&rdquo; and the handover will be audited.</p>

                <div class="mt-4 space-y-3">
                    <div>
                        <label for="authorityReference" class="block text-xs font-medium text-muted">Authority reference</label>
                        <input id="authorityReference" type="text" wire:model="authorityReference"
                            placeholder="e.g. GP-2026-00123"
                            class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                        @error('authorityReference') <p class="mt-1 text-xs text-red-800">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="authoritySubmittedAt" class="block text-xs font-medium text-muted">Submitted at</label>
                        <input id="authoritySubmittedAt" type="datetime-local" wire:model="authoritySubmittedAt"
                            max="{{ now()->format('Y-m-d\TH:i') }}"
                            class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                        @error('authoritySubmittedAt') <p class="mt-1 text-xs text-red-800">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" wire:click="cancelSubmitModal"
                        class="inline-flex items-center rounded-md border border-line bg-white px-3 py-1.5 text-sm hover:bg-paper">
                        Cancel
                    </button>
                    <button type="button" wire:click="confirmSubmitToAuthority"
                        class="inline-flex items-center rounded-md px-3 py-1.5 text-sm font-semibold text-white"
                        style="background: var(--brand);"
                        wire:loading.attr="disabled"
                        wire:target="confirmSubmitToAuthority">
                        <span wire:loading.remove wire:target="confirmSubmitToAuthority">Submit to authority</span>
                        <span wire:loading wire:target="confirmSubmitToAuthority">Submitting&hellip;</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
