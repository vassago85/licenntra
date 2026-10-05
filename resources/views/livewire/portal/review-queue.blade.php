@php
    $toneClasses = [
        'info' => 'bg-blue-50 text-blue-900',
        'warning' => 'bg-amber-50 text-amber-900',
        'success' => 'bg-emerald-50 text-emerald-900',
        'danger' => 'bg-red-50 text-red-900',
        'neutral' => 'bg-gray-50 text-gray-700',
    ];
    $tabLabels = [
        'review' => ['To review', 'Submitted or in document review'],
        'client' => ['With client', 'Changes, quote or payment owed by the dealer'],
        'progress' => ['In progress', 'Quote, payment, datafix, authority, collection'],
        'done' => ['Done', 'Completed, cancelled, archived'],
    ];
@endphp
<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Review queue</h1>
            <p class="text-sm text-muted">{{ $tabLabels[$tab][1] }}. {{ $tab === 'done' ? 'Newest first.' : 'Oldest waiting first.' }}</p>
        </div>
    </div>

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <button type="button" wire:click="showTab('review')" class="rounded-md border bg-white p-3 text-left hover:border-ink {{ $tab === 'review' && ! $pastWarning ? 'border-ink' : 'border-line' }}">
            <p class="text-xs uppercase tracking-wide text-muted">Awaiting review</p>
            <p class="mt-1 text-xl font-semibold">{{ $stats['awaiting_review'] }}</p>
            <p class="mt-0.5 text-xs text-muted">Submitted or in document review</p>
        </button>
        <button type="button" wire:click="showTab('client')" class="rounded-md border bg-white p-3 text-left hover:border-ink {{ $tab === 'client' && ! $pastWarning ? 'border-ink' : 'border-line' }}">
            <p class="text-xs uppercase tracking-wide text-muted">With client</p>
            <p class="mt-1 text-xl font-semibold">{{ $stats['with_client'] }}</p>
            <p class="mt-0.5 text-xs text-muted">Waiting on the dealer</p>
        </button>
        <button type="button" wire:click="showMine" class="rounded-md border bg-white p-3 text-left hover:border-ink {{ $assignment === 'me' ? 'border-ink' : 'border-line' }}">
            <p class="text-xs uppercase tracking-wide text-muted">Assigned to me</p>
            <p class="mt-1 text-xl font-semibold">{{ $stats['assigned_to_me'] }}</p>
            <p class="mt-0.5 text-xs text-muted">Your personal workload</p>
        </button>
        <button type="button" wire:click="showPastWarning" class="rounded-md border bg-white p-3 text-left hover:border-ink {{ $pastWarning ? 'border-ink' : 'border-line' }}">
            <p class="text-xs uppercase tracking-wide text-muted">Past warning time</p>
            <p class="mt-1 text-xl font-semibold {{ $stats['past_warning'] > 0 ? 'text-amber-800' : '' }}">{{ $stats['past_warning'] }}</p>
            <p class="mt-0.5 text-xs text-muted">Waiting longer than the step allows</p>
        </button>
    </div>

    <nav class="mb-3 flex flex-wrap gap-1 border-b border-line" aria-label="Queue tabs">
        @foreach ($tabLabels as $key => [$label])
            <button
                type="button"
                wire:click="showTab('{{ $key }}')"
                class="-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm {{ $tab === $key ? 'border-[color:var(--brand)] font-semibold text-ink' : 'border-transparent text-muted hover:text-ink' }}"
                @if ($tab === $key) aria-current="page" @endif
            >
                {{ $label }}
                <span class="rounded-full bg-paper px-1.5 py-0.5 font-mono text-[11px] ring-1 ring-inset ring-line">{{ $tabs[$key] }}</span>
            </button>
        @endforeach
    </nav>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Reference, VIN, reg no., make or dealer" class="h-9 w-72 rounded-md border border-line bg-white px-2 text-sm">
        <select wire:model.live="assignment" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">Anyone</option>
            <option value="me">Assigned to me</option>
            <option value="unassigned">Unassigned</option>
        </select>
        <select wire:model.live="accountId" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">All dealers</option>
            @foreach ($accounts as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="pastWarning"> Past warning time only</label>
        @if ($hasFilters)
            <button type="button" wire:click="clearFilters" class="text-xs font-medium text-ink hover:underline">Clear filters</button>
        @endif
    </div>

    <section class="overflow-hidden rounded-md border border-line bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Application</th>
                        <th class="px-3 py-2 font-medium">Dealer</th>
                        <th class="px-3 py-2 font-medium">Stage</th>
                        <th class="px-3 py-2 font-medium">Waiting</th>
                        <th class="px-3 py-2 font-medium">Assigned to</th>
                        <th class="px-3 py-2 font-medium text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($rows as $row)
                        @php
                            $isLate = $row->isPastWarningTime();
                            $enteredStageAt = $row->enteredStageAt();
                            $stepWarningHours = $warningHours[$row->stage->value] ?? null;
                            $vehicleLine = collect([
                                trim(($row->vehicle?->make ?? '').' '.($row->vehicle?->model ?? '')),
                                $row->vehicle?->vehicle_register_number,
                                $row->vehicle?->vin ? '…'.substr($row->vehicle->vin, -6) : null,
                            ])->filter()->implode(' · ');
                        @endphp
                        <tr wire:key="rq-{{ $row->id }}" class="{{ $isLate ? 'bg-amber-50/50' : '' }}">
                            <td class="px-3 py-2">
                                <a class="font-mono font-medium hover:underline" href="{{ route('review.show', $row) }}">{{ $row->reference }}</a>
                                @if ($row->dangerous_goods)
                                    <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-900">DG</span>
                                @endif
                                <div class="text-xs text-muted">{{ $vehicleLine !== '' ? $vehicleLine : 'No vehicle details yet' }}</div>
                            </td>
                            <td class="px-3 py-2">{{ $row->clientAccount?->name }}</td>
                            <td class="px-3 py-2">
                                <span class="inline-block whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium {{ $toneClasses[$row->stage->tone()] }}">{{ $row->stage->label() }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs">
                                @if ($row->stage->isTerminal())
                                    <span title="{{ $row->updated_at?->format('d M Y H:i') }}">{{ $row->updated_at?->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                                @else
                                    <span title="In {{ $row->stage->label() }} since {{ $enteredStageAt->format('d M Y H:i') }}">{{ $enteredStageAt->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                                @endif
                                @if ($isLate)
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-900" title="{{ $stepWarningHours ? 'Warning time for '.$row->stage->label().' is '.$stepWarningHours.' h' : 'Past the warning time for this step' }}">Past warning time</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs">
                                @if ($row->reviewer)
                                    {{ $row->reviewer->id === auth()->id() ? 'You' : $row->reviewer->name }}
                                @elseif ($canTake && ! $row->stage->isTerminal())
                                    <button type="button" wire:click="take({{ $row->id }})" class="rounded-md border border-line px-2 py-1 font-medium hover:border-ink">Take it</button>
                                @else
                                    <span class="text-muted">Unassigned</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('review.show', $row) }}" class="inline-flex h-8 items-center rounded-md px-3 text-xs font-semibold text-white" style="background: var(--brand)">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-10 text-center text-sm text-muted">
                                @if ($hasFilters)
                                    Nothing matches these filters. <button type="button" wire:click="clearFilters" class="font-medium text-ink hover:underline">Clear filters</button>
                                @elseif ($tab === 'review')
                                    Nothing waiting for review. Nice.
                                @else
                                    Nothing here.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-line px-3 py-2">{{ $rows->links() }}</div>
        @endif
    </section>
</div>
