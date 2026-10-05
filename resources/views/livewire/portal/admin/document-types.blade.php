<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Document library</h1>
            <p class="text-sm text-muted">The kinds of document clients upload, and the rules that decide when each one is required.</p>
        </div>
        @if (! $showForm)
            <button wire:click="create" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Add document type</button>
        @endif
    </div>

    @include('livewire.portal.admin.partials.section-tabs', ['tabs' => ['admin.document-rules' => 'Rules', 'admin.document-types' => 'Document types']])

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif
    @if ($errorMessage)
        <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $errorMessage }}</p>
    @endif

    @if ($showForm)
        <section class="mb-6 rounded-md border border-line bg-white p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit document type' : 'New document type' }}</h2>
                <button wire:click="cancel" class="text-xs text-muted hover:underline">Cancel</button>
            </div>
            <form wire:submit="save" class="grid gap-3 md:grid-cols-3">
                <label class="block text-sm">
                    <span class="text-muted">Code</span>
                    <input wire:model="code" type="text" placeholder="e.g. weighbridge_certificate" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 font-mono text-sm">
                    @error('code') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm md:col-span-2">
                    <span class="text-muted">Name shown to clients</span>
                    <input wire:model="name" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Maximum age (days)</span>
                    <input wire:model="maxAgeDays" type="number" min="1" placeholder="No limit" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('maxAgeDays') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="flex items-center gap-2 self-end pb-2 text-sm">
                    <input wire:model="isIdentityDocument" type="checkbox"> Identity document
                </label>
                <label class="flex items-center gap-2 self-end pb-2 text-sm">
                    <input wire:model="requiresOriginal" type="checkbox"> Physical original required
                </label>
                <div class="md:col-span-3">
                    <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">{{ $editingId ? 'Save changes' : 'Add document type' }}</button>
                </div>
            </form>
        </section>
    @endif

    <section class="overflow-hidden rounded-md border border-line bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Document</th>
                        <th class="px-3 py-2 font-medium">Flags</th>
                        <th class="px-3 py-2 font-medium">Max age</th>
                        <th class="px-3 py-2 font-medium text-right">Rules</th>
                        <th class="px-3 py-2 font-medium text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($types as $type)
                        <tr wire:key="doc-type-{{ $type->id }}">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $type->name }}</div>
                                <div class="font-mono text-xs text-muted">{{ $type->code }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                @if ($type->is_identity_document)
                                    <span class="mr-1 inline-block rounded-full border border-line px-2 py-0.5">Identity</span>
                                @endif
                                @if ($type->requires_original)
                                    <span class="inline-block rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-amber-900">Original</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs">{{ $type->max_age_days ? $type->max_age_days.' days' : '—' }}</td>
                            <td class="px-3 py-2 text-right text-xs tabular-nums">{{ $type->rules_count }}</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3 text-xs">
                                    <button wire:click="edit({{ $type->id }})" class="hover:underline">Edit</button>
                                    @if ($type->rules_count === 0)
                                        <button wire:click="delete({{ $type->id }})" wire:confirm="Delete {{ $type->name }}?" class="text-red-700 hover:underline">Delete</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-10 text-center text-muted">No document types yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
