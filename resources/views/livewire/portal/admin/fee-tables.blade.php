<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Fee tables</h1>
            <p class="text-sm text-muted">One table per province. Prices are set on a draft version and go live once a second person approves it.</p>
        </div>
        @if (! $showForm)
            <button wire:click="create" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Add table</button>
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
                <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit table' : 'New table' }}</h2>
                <button wire:click="cancel" class="text-xs text-muted hover:underline">Cancel</button>
            </div>
            <form wire:submit="save" class="flex flex-wrap items-end gap-3">
                <label class="block text-sm">
                    <span class="text-muted">Province</span>
                    <select wire:model="province" class="mt-1 h-10 w-56 rounded-md border border-line bg-white px-3 text-sm">
                        <option value="">Choose…</option>
                        @foreach ($provinces as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                    @error('province') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block grow text-sm">
                    <span class="text-muted">Name</span>
                    <input wire:model="name" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">{{ $editingId ? 'Save' : 'Create' }}</button>
            </form>
        </section>
    @endif

    <section class="overflow-hidden rounded-md border border-line bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                <tr>
                    <th class="px-3 py-2 font-medium">Table</th>
                    <th class="px-3 py-2 font-medium">Live version</th>
                    <th class="px-3 py-2 font-medium">Drafts</th>
                    <th class="px-3 py-2 font-medium text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($tables as $table)
                    @php
                        $live = $table->versions->firstWhere('status', 'active');
                        $drafts = $table->versions->where('status', 'draft');
                    @endphp
                    <tr wire:key="fee-table-{{ $table->id }}">
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ $table->name }}</div>
                            <div class="text-xs text-muted">{{ $table->province?->label() }}</div>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if ($live)
                                <a href="{{ route('admin.fee-table-versions.edit', $live) }}" class="hover:underline">v{{ $live->version }}</a>
                                <span class="text-muted">· {{ $live->lines_count }} lines · since {{ ($live->effective_from ?? $live->approved_at)?->format('d M Y') ?? '—' }}</span>
                            @else
                                <span class="text-amber-800">No live version</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @forelse ($drafts as $draft)
                                <a href="{{ route('admin.fee-table-versions.edit', $draft) }}" class="mr-2 hover:underline">v{{ $draft->version }}</a>
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-3 text-xs">
                                <button wire:click="edit({{ $table->id }})" class="hover:underline">Rename</button>
                                @if ($table->versions_count === 0)
                                    <button wire:click="delete({{ $table->id }})" wire:confirm="Delete {{ $table->name }}?" class="text-red-700 hover:underline">Delete</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-10 text-center text-muted">No fee tables yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
