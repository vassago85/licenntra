<div>
    <div class="mb-4">
        <h1 class="text-xl font-semibold">Fee tables</h1>
        <p class="text-sm text-muted">Every fee line across all provinces. Amounts on draft versions can be corrected inline; live prices change through a new draft.</p>
    </div>

    @include('livewire.portal.admin.partials.fee-tabs')

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif
    @if ($errorMessage)
        <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $errorMessage }}</p>
    @endif

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Label or code" class="h-9 w-56 rounded-md border border-line bg-white px-2 text-sm">
        <select wire:model.live="statusFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">Any version</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }} versions</option>
            @endforeach
        </select>
        <select wire:model.live="provinceFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">All provinces</option>
            @foreach ($provinces as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="categoryFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">All categories</option>
            <option value="service">Service fees</option>
            @foreach ($categories as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="taxFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">Any tax</option>
            @foreach ($taxTreatments as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
            @endforeach
        </select>
        <span class="text-xs text-muted">{{ number_format($lines->total()) }} lines</span>
    </div>

    <section class="overflow-hidden rounded-md border border-line bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Table</th>
                        <th class="px-3 py-2 font-medium">Line</th>
                        <th class="px-3 py-2 font-medium">Category / band</th>
                        <th class="px-3 py-2 font-medium">Tax</th>
                        <th class="px-3 py-2 font-medium">Visible</th>
                        <th class="px-3 py-2 font-medium text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($lines as $row)
                        @php
                            $isDraft = $row->version?->isEditable() ?? false;
                            $band = \App\Livewire\Portal\Admin\FeeTableVersionEditor::describeTareBand($row);
                        @endphp
                        <tr wire:key="fee-line-{{ $row->id }}">
                            <td class="px-3 py-2 text-xs">
                                <div>{{ $row->version?->feeTable?->province?->label() }}</div>
                                <a href="{{ route('admin.fee-table-versions.edit', $row->fee_table_version_id) }}" class="text-muted hover:underline">v{{ $row->version?->version }} · {{ $statuses[$row->version?->status] ?? $row->version?->status }}</a>
                            </td>
                            <td class="px-3 py-2">
                                <div>{{ $row->label }}</div>
                                <div class="font-mono text-xs text-muted">{{ $row->code }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                {{ $row->licence_category?->label() ?? 'Service fee' }}
                                @if ($band !== '')
                                    <div class="text-muted">{{ $band }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs">{{ $row->tax_treatment?->shortLabel() ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">
                                @if ($isDraft)
                                    <button wire:click="toggleVisible({{ $row->id }})" class="hover:underline">{{ $row->client_visible ? 'Yes' : 'No' }}</button>
                                @else
                                    {{ $row->client_visible ? 'Yes' : 'No' }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right">
                                @if ($isDraft)
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value="{{ number_format($row->amount_cents / 100, 2, '.', '') }}"
                                        wire:change="updateAmount({{ $row->id }}, $event.target.value)"
                                        aria-label="Amount for {{ $row->label }}"
                                        class="h-8 w-28 rounded-md border border-line bg-white px-2 text-right text-sm tabular-nums"
                                    >
                                @else
                                    <span class="tabular-nums">{{ \App\Support\Money::rands($row->amount_cents) }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-10 text-center text-muted">No lines match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($lines->hasPages())
            <div class="border-t border-line px-3 py-2">{{ $lines->links() }}</div>
        @endif
    </section>
</div>
