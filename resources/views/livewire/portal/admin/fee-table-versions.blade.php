<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Fee tables</h1>
            <p class="text-sm text-muted">Draft a new version to change prices. Someone other than the drafter approves it, and it replaces the live version.</p>
        </div>
        @if (! $showForm)
            <button wire:click="create" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">New draft</button>
        @endif
    </div>

    @include('livewire.portal.admin.partials.fee-tabs')

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif
    @if ($errorMessage)
        <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $errorMessage }}</p>
    @endif

    @if ($showForm)
        <section class="mb-6 rounded-md border border-line bg-white p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">New draft version</h2>
                <button wire:click="cancel" class="text-xs text-muted hover:underline">Cancel</button>
            </div>
            <form wire:submit="createDraft" class="grid gap-3 md:grid-cols-3">
                <label class="block text-sm">
                    <span class="text-muted">Fee table</span>
                    <select wire:model="feeTableId" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        <option value="">Choose…</option>
                        @foreach ($tables as $table)
                            <option value="{{ $table->id }}">{{ $table->province?->label() }} — {{ $table->name }}</option>
                        @endforeach
                    </select>
                    @error('feeTableId') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Effective from</span>
                    <input wire:model="effectiveFrom" type="date" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('effectiveFrom') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="flex items-center gap-2 self-end pb-2 text-sm">
                    <input wire:model="copyLiveLines" type="checkbox"> Start from the live version's lines
                </label>
                <label class="block text-sm md:col-span-3">
                    <span class="text-muted">Notes</span>
                    <textarea wire:model="notes" rows="2" placeholder="e.g. 2027 tariff increase" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm"></textarea>
                    @error('notes') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <div class="md:col-span-3">
                    <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Create draft and edit lines</button>
                </div>
            </form>
        </section>
    @endif

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <select wire:model.live="tableFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">All tables</option>
            @foreach ($tables as $table)
                <option value="{{ $table->id }}">{{ $table->province?->label() }} — {{ $table->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="statusFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">Any status</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <section class="overflow-hidden rounded-md border border-line bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Version</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Effective</th>
                        <th class="px-3 py-2 font-medium text-right">Lines</th>
                        <th class="px-3 py-2 font-medium">Drafted / approved</th>
                        <th class="px-3 py-2 font-medium text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($versions as $version)
                        @php
                            $statusClass = match ($version->status) {
                                'draft' => 'border-amber-200 bg-amber-50 text-amber-900',
                                'active' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                                default => 'border-line bg-paper text-muted',
                            };
                            $canApprove = $version->status === 'draft' && (int) $version->created_by !== $currentUserId;
                        @endphp
                        <tr wire:key="version-{{ $version->id }}">
                            <td class="px-3 py-2">
                                <a href="{{ route('admin.fee-table-versions.edit', $version) }}" class="font-medium hover:underline">{{ $version->feeTable?->name }} v{{ $version->version }}</a>
                                <div class="text-xs text-muted">{{ $version->feeTable?->province?->label() }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <span class="inline-block rounded-full border px-2 py-0.5 text-xs {{ $statusClass }}">{{ $statuses[$version->status] ?? ucfirst($version->status) }}</span>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                {{ $version->effective_from?->format('d M Y') ?? 'On approval' }}
                                @if ($version->effective_until)
                                    <span class="text-muted">→ {{ $version->effective_until->format('d M Y') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right text-xs tabular-nums">{{ $version->lines_count }}</td>
                            <td class="px-3 py-2 text-xs">
                                <div>{{ $version->creator?->name ?? 'System' }}</div>
                                @if ($version->approved_at)
                                    <div class="text-muted">Approved by {{ $version->approver?->name ?? '—' }}, {{ $version->approved_at->format('d M Y') }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap justify-end gap-3 text-xs">
                                    <a href="{{ route('admin.fee-table-versions.edit', $version) }}" class="hover:underline">{{ $version->status === 'draft' ? 'Edit lines' : 'View lines' }}</a>
                                    @if ($canApprove)
                                        <button wire:click="approve({{ $version->id }})" wire:confirm="Approve and publish {{ $version->feeTable?->name }} v{{ $version->version }}? It replaces the live version and its lines become read-only." class="font-semibold text-emerald-700 hover:underline">Approve &amp; publish</button>
                                    @elseif ($version->status === 'draft')
                                        <span class="text-muted" title="The person who drafted a version cannot approve it.">Awaiting another approver</span>
                                    @endif
                                    @if ($version->status === 'draft')
                                        <button wire:click="delete({{ $version->id }})" wire:confirm="Delete this draft and its lines?" class="text-red-700 hover:underline">Delete</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-10 text-center text-muted">No versions match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($versions->hasPages())
            <div class="border-t border-line px-3 py-2">{{ $versions->links() }}</div>
        @endif
    </section>
</div>
