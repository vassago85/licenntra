<div>
    <div class="mb-4 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold">Fleet licence review</h1>
            <p class="mt-1 text-sm text-muted">Upload motor-vehicle licences for a fleet. Each file is read by OCR, then confirm the expiry, register number, and VIN below.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <section class="mb-6 rounded-md border border-line bg-white p-4">
        <h2 class="mb-3 text-sm font-semibold">Upload a licence</h2>
        <form wire:submit="uploadLicence" class="flex flex-col gap-3">
            <div class="flex flex-col gap-1 text-sm">
                <label for="uploadFleetId" class="text-xs font-medium uppercase tracking-wide text-muted">Fleet</label>
                <select wire:model="uploadFleetId" id="uploadFleetId" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
                    <option value="">Choose a fleet…</option>
                    @foreach ($fleets as $fleet)
                        <option value="{{ $fleet->id }}">{{ $fleet->name }}</option>
                    @endforeach
                </select>
                @error('uploadFleetId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
            <div class="flex flex-col gap-1 text-sm">
                <label class="text-xs font-medium uppercase tracking-wide text-muted">Licence files</label>
                <input type="file" wire:model="uploads.0" accept="application/pdf,image/jpeg,image/png" class="text-sm">
                <input type="file" wire:model="uploads.1" accept="application/pdf,image/jpeg,image/png" class="text-sm">
                <input type="file" wire:model="uploads.2" accept="application/pdf,image/jpeg,image/png" class="text-sm">
                @error('uploads') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                @error('upload') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
            <button type="submit" class="self-start rounded-md border border-line bg-ink px-3 py-2 text-sm text-white">Upload and queue OCR</button>
        </form>
    </section>

    <section class="rounded-md border border-line bg-white">
        <header class="flex items-center justify-between gap-3 border-b border-line px-3 py-2">
            <h2 class="text-sm font-semibold">Pending confirmation</h2>
            <span class="text-xs text-muted">{{ $pending->count() }} waiting</span>
        </header>
        <table class="w-full text-left text-sm">
            <thead class="text-xs text-muted">
                <tr class="border-b border-line">
                    <th class="px-3 py-2 font-medium">Fleet</th>
                    <th class="px-3 py-2 font-medium">File</th>
                    <th class="px-3 py-2 font-medium">OCR status</th>
                    <th class="px-3 py-2 font-medium">Expiry hint</th>
                    <th class="px-3 py-2 font-medium">Register hint</th>
                    <th class="px-3 py-2 font-medium">Uploaded</th>
                    <th class="px-3 py-2 font-medium text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pending as $document)
                    <tr class="border-b border-line last:border-0">
                        <td class="px-3 py-2">{{ $document->fleetVehicle?->clientAccount?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ $document->documentVersion?->original_filename }}</td>
                        <td class="px-3 py-2 text-xs">{{ $document->ocr_status?->label() }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $document->ocr_expiry_candidate?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $document->ocr_register_candidate ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs text-muted">{{ $document->created_at?->diffForHumans() }}</td>
                        <td class="px-3 py-2 text-right">
                            <a href="{{ route('fleet.review.show', $document) }}" class="rounded-md border border-line px-2 py-1 text-xs">Confirm</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-6 text-muted">Nothing waiting for confirmation.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
