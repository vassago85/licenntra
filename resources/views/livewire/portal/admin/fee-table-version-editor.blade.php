<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="{{ route('admin.fee-table-versions') }}" class="text-xs text-muted hover:underline">← All versions</a>
            <h1 class="text-xl font-semibold">{{ $version->feeTable?->name }} v{{ $version->version }}</h1>
            <p class="text-sm text-muted">
                {{ $version->feeTable?->province?->label() }} ·
                @if ($version->status === 'draft')
                    <span class="text-amber-800">Draft</span> by {{ $version->creator?->name ?? 'System' }}
                @elseif ($version->status === 'active')
                    <span class="text-emerald-700">Live</span> since {{ $version->approved_at?->format('d M Y') }}, approved by {{ $version->approver?->name ?? '—' }}
                @else
                    Superseded
                @endif
            </p>
        </div>
        @if ($isEditable)
            <div class="flex items-center gap-3">
                @if ($canApprove)
                    <button wire:click="approve" wire:confirm="Approve and publish this version? It replaces the live version and its lines become read-only." class="h-10 rounded-md bg-emerald-700 px-4 text-sm font-semibold text-white">Approve &amp; publish</button>
                @else
                    <span class="text-xs text-muted">Another configurator must approve this draft.</span>
                @endif
            </div>
        @endif
    </div>

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif
    @if ($errorMessage)
        <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $errorMessage }}</p>
    @endif

    @if ($isEditable)
        <section class="mb-4 rounded-md border border-line bg-white p-4">
            <form wire:submit="saveDetails" class="flex flex-wrap items-end gap-3">
                <label class="block text-sm">
                    <span class="text-muted">Effective from</span>
                    <input wire:model="effectiveFrom" type="date" class="mt-1 h-9 rounded-md border border-line bg-white px-2 text-sm">
                    @error('effectiveFrom') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Effective until</span>
                    <input wire:model="effectiveUntil" type="date" class="mt-1 h-9 rounded-md border border-line bg-white px-2 text-sm">
                    @error('effectiveUntil') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block grow text-sm">
                    <span class="text-muted">Notes</span>
                    <input wire:model="notes" type="text" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                </label>
                <button type="submit" class="h-9 rounded-md border border-line bg-white px-3 text-sm font-medium hover:bg-paper">Save details</button>
            </form>
        </section>
    @elseif ($version->notes)
        <p class="mb-4 text-sm text-muted">{{ $version->notes }}</p>
    @endif

    <div class="mb-2 flex items-center justify-between">
        <h2 class="text-sm font-semibold">{{ $lines->count() }} fee {{ \Illuminate\Support\Str::plural('line', $lines->count()) }}</h2>
        @if ($isEditable && ! $showLineForm)
            <button wire:click="addLine" class="h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Add line</button>
        @endif
    </div>
    <p class="mb-3 text-xs text-muted">Annual licence lines stay Exempt. Our own service, admin and plate fees are Standard-rated (VAT applies).</p>

    @if ($showLineForm)
        <section class="mb-4 rounded-md border border-line bg-white p-4">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">{{ $editingLineId ? 'Edit line' : 'New line' }}</h3>
                <button wire:click="cancelLine" class="text-xs text-muted hover:underline">Cancel</button>
            </div>
            <form wire:submit="saveLine" class="grid gap-3 md:grid-cols-6">
                <label class="block text-sm">
                    <span class="text-muted">Code</span>
                    <input wire:model="line.code" type="text" placeholder="licence" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 font-mono text-sm">
                    @error('line.code') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm md:col-span-3">
                    <span class="text-muted">Label on quote</span>
                    <input wire:model="line.label" type="text" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                    @error('line.label') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Amount (R)</span>
                    <input wire:model="line.amount_rand" type="number" min="0" step="0.01" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 text-right text-sm tabular-nums">
                    @error('line.amount_rand') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Sort order</span>
                    <input wire:model="line.sort_order" type="number" min="0" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                    @error('line.sort_order') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Tax</span>
                    <select wire:model="line.tax_treatment" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                        @foreach ($taxTreatments as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Period</span>
                    <select wire:model="line.period" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                        @foreach ($periods as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm md:col-span-2">
                    <span class="text-muted">Vehicle category (licence bands)</span>
                    <select wire:model="line.licence_category" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                        <option value="">— Service fee —</option>
                        @foreach ($licenceCategories as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                    @error('line.licence_category') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Tare from (kg)</span>
                    <input wire:model="line.tare_min_kg" type="number" min="0" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                    @error('line.tare_min_kg') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Tare to (kg)</span>
                    <input wire:model="line.tare_max_kg" type="number" min="0" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                    @error('line.tare_max_kg') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm md:col-span-2">
                    <span class="text-muted">Service type match</span>
                    <input wire:model="line.service_type" type="text" placeholder="Any" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 font-mono text-sm">
                </label>
                <label class="block text-sm md:col-span-2">
                    <span class="text-muted">Business category match</span>
                    <input wire:model="line.vehicle_category" type="text" placeholder="Any (passenger / commercial)" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 font-mono text-sm">
                </label>
                <label class="block text-sm md:col-span-2">
                    <span class="text-muted">Request type match</span>
                    <input wire:model="line.request_type" type="text" placeholder="Any" class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 font-mono text-sm">
                </label>
                <div class="flex flex-wrap items-center gap-4 md:col-span-6">
                    <label class="flex items-center gap-2 text-sm">
                        <input wire:model="line.client_visible" type="checkbox"> Show on client quote
                    </label>
                    <button type="submit" class="h-9 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">{{ $editingLineId ? 'Save line' : 'Add line' }}</button>
                </div>
            </form>
        </section>
    @endif

    <section class="overflow-hidden rounded-md border border-line bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Line</th>
                        <th class="px-3 py-2 font-medium">Category / band</th>
                        <th class="px-3 py-2 font-medium">Tax</th>
                        <th class="px-3 py-2 font-medium text-right">Amount</th>
                        @if ($isEditable)
                            <th class="px-3 py-2 font-medium text-right"><span class="sr-only">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($lines as $row)
                        <tr wire:key="line-{{ $row->id }}" class="{{ $editingLineId === $row->id ? 'bg-paper' : '' }}">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $row->label }}</div>
                                <div class="text-xs text-muted">
                                    <span class="font-mono">{{ $row->code }}</span>
                                    · {{ $row->period?->label() }}
                                    @unless ($row->client_visible) · <span class="text-amber-800">hidden from client</span> @endunless
                                    @foreach (array_filter([$row->service_type, $row->vehicle_category, $row->request_type]) as $match)
                                        · <span class="font-mono">{{ $match }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                {{ $row->licence_category?->label() ?? 'Service fee' }}
                                @php
                                    $band = \App\Livewire\Portal\Admin\FeeTableVersionEditor::describeTareBand($row);
                                @endphp
                                @if ($band !== '')
                                    <div class="text-muted">{{ $band }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs">{{ $row->tax_treatment?->shortLabel() ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ \App\Support\Money::rands($row->amount_cents) }}</td>
                            @if ($isEditable)
                                <td class="px-3 py-2">
                                    <div class="flex justify-end gap-3 text-xs">
                                        <button wire:click="editLine({{ $row->id }})" class="hover:underline">Edit</button>
                                        <button wire:click="duplicateLine({{ $row->id }})" class="hover:underline">Duplicate</button>
                                        <button wire:click="deleteLine({{ $row->id }})" wire:confirm="Delete {{ $row->label }}?" class="text-red-700 hover:underline">Delete</button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isEditable ? 5 : 4 }}" class="px-3 py-10 text-center text-muted">No lines yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
