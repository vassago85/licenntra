<div class="space-y-4">
    <div>
        <h1 class="text-xl font-semibold">
            {{ $handover ? '#' . $handover->id . ' - ' . $handover->direction->shortLabel() : 'New hand-over' }}
        </h1>
        <p class="text-sm text-muted">
            @if ($handover?->isCompleted())
                Confirmed digitally on {{ $handover->confirmed_at?->format('d M Y H:i') }} - this hand-over is read-only. A paper POD/POC is optional; print or attach a signed scan below if the dealership wants one on file.
            @elseif ($direction === 'collection')
                Collection: documents the licensing-authority representative is picking up to lodge at the authority. Confirm the hand-over digitally when they are at the counter - a printed POD is optional.
            @elseif ($direction === 'delivery')
                Delivery: documents the licensing-authority representative is dropping off at the dealership. Confirm the hand-over digitally when they are at the counter - a printed POC is optional.
            @else
                Capture everyone and everything that changes hands during the visit. The digital on-screen confirmation is the record of truth; printing paper is optional.
            @endif
        </p>
    </div>

    @if (session('status'))
        <p class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $errors->first() }}</div>
    @endif

    <form wire:submit.prevent="save" class="space-y-4">
        <section class="grid gap-3 rounded-md border border-line bg-white p-3 sm:grid-cols-2">
            <h2 class="text-sm font-semibold sm:col-span-2">Visit</h2>
            <label class="text-sm">Direction
                <select wire:model.live="direction" @disabled($handover?->isCompleted()) class="mt-1 h-10 w-full rounded-md border border-line px-2 py-2 text-sm">
                    <option value="">Choose</option>
                    @foreach ($directions as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Authority company (optional)
                <input wire:model.blur="counterparty_company" @disabled($handover?->isCompleted()) class="mt-1 h-10 w-full rounded-md border border-line px-2 py-2 text-sm" placeholder="Licensing authority">
            </label>
            <label class="text-sm">Representative name
                <input wire:model.blur="counterparty_name" @disabled($handover?->isCompleted()) class="mt-1 h-10 w-full rounded-md border border-line px-2 py-2 text-sm" placeholder="e.g. Thandi Mahlangu">
            </label>
            <label class="text-sm">Representative ID / employee number (optional)
                <input wire:model.blur="counterparty_identifier" @disabled($handover?->isCompleted()) class="mt-1 h-10 w-full rounded-md border border-line px-2 py-2 text-sm font-mono">
            </label>
            <label class="text-sm sm:col-span-2">Dealership person on the counter
                <input wire:model.blur="dealer_person_name" @disabled($handover?->isCompleted()) class="mt-1 h-10 w-full rounded-md border border-line px-2 py-2 text-sm" placeholder="Your name - the one signing on behalf of the dealership">
            </label>
        </section>

        <section class="space-y-3 rounded-md border border-line bg-white p-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Applications in this hand-over</h2>
                <span class="text-xs text-muted">{{ count($application_ids) }} selected</span>
            </div>
            <div class="max-h-80 space-y-2 overflow-y-auto rounded-md border border-line bg-paper p-2">
                @forelse ($availableApplications as $application)
                    @php($isSelected = in_array($application->id, $application_ids, true))
                    <label class="flex flex-col gap-2 rounded-md border {{ $isSelected ? 'border-emerald-300 bg-emerald-50' : 'border-line bg-white' }} px-2 py-2 text-sm sm:flex-row sm:items-start" wire:key="handover-app-{{ $application->id }}">
                        <input type="checkbox" wire:model.live="application_ids" value="{{ $application->id }}" @disabled($handover?->isCompleted()) class="mt-1 sm:mt-0">
                        <div class="flex-1 min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{ $application->reference }}</span>
                                <span class="rounded border border-line bg-white px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted">{{ $application->stage?->label() }}</span>
                            </div>
                            @if ($isSelected)
                                <input wire:model.blur="line_items.{{ $application->id }}" @disabled($handover?->isCompleted()) class="h-9 w-full rounded-md border border-line bg-white px-2 text-xs" placeholder="What changed hands for this app? e.g. Original NaTIS + stamped RC1">
                            @endif
                        </div>
                    </label>
                @empty
                    <p class="text-xs text-muted">No applications on your dealership yet.</p>
                @endforelse
            </div>
        </section>

        <section class="space-y-3 rounded-md border border-line bg-white p-3">
            <h2 class="text-sm font-semibold">Narrative</h2>
            <label class="block text-sm">Items summary (printed on the POD/POC)
                <textarea wire:model.blur="items_summary" @disabled($handover?->isCompleted()) rows="3" maxlength="2000" class="mt-1 w-full rounded-md border border-line px-2 py-2 text-sm" placeholder="e.g. 3 x licence discs, 1 x NaTIS registration certificate, 2 x stamped RC1"></textarea>
            </label>
            <label class="block text-sm">Internal notes (not printed)
                <textarea wire:model.blur="notes" @disabled($handover?->isCompleted()) rows="2" maxlength="2000" class="mt-1 w-full rounded-md border border-line px-2 py-2 text-sm" placeholder="Anything the next shift should know"></textarea>
            </label>
        </section>

        <div class="flex flex-wrap gap-2">
            @unless ($handover?->isCompleted())
                <button type="submit" class="h-10 rounded-md border border-line bg-white px-3 text-sm font-semibold">Save</button>
                @if ($handover)
                    <button type="button" wire:click="confirm" wire:confirm="Confirm this hand-over digitally? It becomes read-only after." class="h-10 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">
                        Confirm digitally (both parties on-screen)
                    </button>
                    <a href="{{ route('handovers.print', $handover) }}" target="_blank" rel="noopener" class="h-10 rounded-md border border-line bg-white px-3 text-sm text-muted hover:bg-paper">Print paper POD/POC (optional)</a>
                    <button type="button" wire:click="delete" wire:confirm="Delete this pending hand-over?" class="h-10 rounded-md border border-red-200 bg-white px-3 text-sm font-semibold text-red-900">Delete</button>
                @endif
            @else
                <a href="{{ route('handovers.print', $handover) }}" target="_blank" rel="noopener" class="h-10 rounded-md border border-line bg-white px-3 text-sm text-muted hover:bg-paper">Print paper POD/POC (optional)</a>
            @endunless
        </div>
    </form>

    @if ($handover)
        <section class="space-y-3 rounded-md border border-dashed border-line bg-white p-3 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Signed paper copy <span class="text-xs font-normal text-muted">(optional)</span></h2>
                @if (! $handover->signed_file_path)
                    <span class="rounded-full border border-line bg-paper px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted">Not needed for the record</span>
                @endif
            </div>
            @if ($handover->signed_file_path)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                    <span>
                        <span class="font-semibold">On file:</span> {{ $handover->signed_file_original_name }}
                        <span class="text-xs">({{ number_format(($handover->signed_file_size ?? 0) / 1024, 0) }} KB, uploaded {{ $handover->signed_file_uploaded_at?->format('d M Y H:i') }})</span>
                    </span>
                    <a href="{{ route('handovers.signed.download', $handover) }}" class="rounded border border-emerald-300 bg-white px-2 py-1 text-xs font-semibold hover:bg-emerald-100">Download</a>
                </div>
            @else
                <p class="text-xs text-muted">The digital on-screen confirmation above is the record of truth. Only attach a signed paper copy here if the dealership wants a hard-copy trail (for example, old-school audit requirements).</p>
            @endif
            <div class="flex flex-wrap items-center gap-2">
                <input type="file" wire:model="signedScan" accept=".pdf,.jpg,.jpeg,.png" class="min-w-0 text-xs">
                <button type="button" wire:click="uploadSigned" class="h-9 rounded-md border border-line bg-white px-3 text-xs font-semibold hover:bg-paper">
                    {{ $handover->signed_file_path ? 'Replace signed scan' : 'Attach signed scan' }}
                </button>
            </div>
            <p class="text-[11px] text-muted">PDF, JPG, or PNG - 15 MB max</p>
            @error('signedScan')
                <p class="text-xs text-red-800">{{ $message }}</p>
            @enderror
        </section>
    @endif
</div>
