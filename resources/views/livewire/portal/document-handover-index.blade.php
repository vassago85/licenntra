<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Hand-overs</h1>
            <p class="text-sm text-muted">
                Record every time documents change hands between the licensing company and the client: collections of paperwork to prepare and lodge, and deliveries of finished paperwork back to the client. Confirm the hand-over digitally when both parties are at the counter - a printed paper POD/POC is optional.
            </p>
        </div>
        @can('create', \App\Models\DocumentHandover::class)
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('handovers.create', ['direction' => 'delivery']) }}" class="h-10 rounded-md border border-line bg-white px-3 text-sm font-semibold hover:bg-paper">+ Delivery (to client)</a>
                <a href="{{ route('handovers.create', ['direction' => 'collection']) }}" class="h-10 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">+ Collection (from client)</a>
            </div>
        @endcan
    </div>

    @if (session('status'))
        <p class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ session('status') }}</p>
    @endif

    <div class="mb-3 flex flex-wrap items-center gap-2 rounded-md border border-line bg-white px-3 py-2 text-sm">
        <label class="flex items-center gap-2">
            <span class="text-xs text-muted">Direction</span>
            <select wire:model.live="directionFilter" class="h-9 rounded-md border border-line px-2 text-sm">
                <option value="">All</option>
                @foreach ($directions as $direction)
                    <option value="{{ $direction->value }}">{{ $direction->shortLabel() }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex items-center gap-2">
            <span class="text-xs text-muted">Status</span>
            <select wire:model.live="statusFilter" class="h-9 rounded-md border border-line px-2 text-sm">
                <option value="">All</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        @if ($directionFilter !== '' || $statusFilter !== '')
            <button type="button" wire:click="clearFilters" class="h-9 rounded-md border border-line px-2 text-xs">Clear</button>
        @endif
    </div>

    <div class="overflow-hidden rounded-md border border-line bg-white">
        <ul class="divide-y divide-line text-sm">
            @forelse ($handovers as $handover)
                <li class="flex flex-col gap-2 px-3 py-3 sm:flex-row sm:items-start sm:justify-between" wire:key="handover-{{ $handover->id }}">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">#{{ $handover->id }} - {{ $handover->direction->shortLabel() }}</span>
                            @unless (auth()->user()->isClient())
                                <span class="text-xs text-muted">{{ $handover->clientAccount?->name }}</span>
                            @endunless
                            @if ($handover->isCompleted())
                                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-900">Completed {{ $handover->confirmed_at?->format('d M H:i') }}</span>
                            @else
                                <span class="rounded-full border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">Pending</span>
                            @endif
                            @if ($handover->signed_file_path)
                                <span class="rounded-full border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-blue-900">Signed scan on file</span>
                            @endif
                        </div>
                        <p class="text-xs text-muted">
                            {{ $handover->applications_count }} {{ \Illuminate\Support\Str::plural('application', $handover->applications_count) }}
                            @if ($handover->counterparty_name)
                                · {{ $handover->counterparty_company ?: $licensingCompany }} - {{ $handover->counterparty_name }}
                            @endif
                            · Created {{ $handover->created_at->format('d M Y H:i') }}
                            @if ($handover->createdBy) by {{ $handover->createdBy->name }} @endif
                        </p>
                        @if ($handover->items_summary)
                            <p class="text-xs text-ink">{{ \Illuminate\Support\Str::limit($handover->items_summary, 140) }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2 sm:flex-col sm:items-end">
                        <a href="{{ route('handovers.edit', $handover) }}" class="h-9 rounded-md border border-line bg-white px-3 text-xs font-semibold hover:bg-paper">
                            {{ $handover->isPending() ? 'Open & confirm' : 'View' }}
                        </a>
                        <a href="{{ route('handovers.print', $handover) }}" target="_blank" rel="noopener" class="h-9 rounded-md border border-line bg-white px-3 text-xs font-semibold hover:bg-paper">Print</a>
                    </div>
                </li>
            @empty
                <li class="px-3 py-6 text-center text-sm text-muted">No hand-overs recorded yet. Start one above when documents are about to change hands.</li>
            @endforelse
        </ul>
    </div>

    <div class="mt-3">{{ $handovers->links() }}</div>
</div>
