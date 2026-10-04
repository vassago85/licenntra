<div>
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('fleet.review.queue') }}" class="text-xs text-muted hover:underline">← Back to queue</a>
    </div>

    <div class="mb-4">
        <h1 class="text-xl font-semibold">Confirm fleet vehicle</h1>
        <p class="mt-1 text-sm text-muted">
            Fleet: <strong>{{ $document->fleetVehicle?->clientAccount?->name }}</strong>.
            File: <strong>{{ $document->documentVersion?->original_filename }}</strong>.
            @if ($document->documentVersion)
                <a href="{{ route('documents.download', $document->documentVersion) }}" class="ml-2 text-xs underline">Download</a>
            @endif
        </p>
    </div>

    <section class="mb-4 rounded-md border border-line bg-paper p-3 text-sm">
        <div class="mb-1 text-xs font-medium uppercase tracking-wide text-muted">OCR hints</div>
        <ul class="space-y-1 text-xs">
            <li>Status: <strong>{{ $document->ocr_status?->label() }}</strong></li>
            <li>Expiry: <code>{{ $document->ocr_expiry_candidate?->format('Y-m-d') ?? '—' }}</code></li>
            <li>Register: <code>{{ $document->ocr_register_candidate ?? '—' }}</code></li>
            <li>VIN: <code>{{ $document->ocr_vin_candidate ?? '—' }}</code></li>
            @if ($document->ocr_notes)
                <li class="text-muted">{{ $document->ocr_notes }}</li>
            @endif
        </ul>
    </section>

    <form wire:submit="confirm" class="grid grid-cols-1 gap-3 rounded-md border border-line bg-white p-4 text-sm sm:grid-cols-2">
        <label class="flex flex-col gap-1">
            <span class="text-xs font-medium uppercase tracking-wide text-muted">Vehicle register number</span>
            <input type="text" wire:model="vehicle_register_number" class="h-9 rounded-md border border-line px-2 font-mono text-sm">
            @error('vehicle_register_number') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-xs font-medium uppercase tracking-wide text-muted">VIN</span>
            <input type="text" wire:model="vin" class="h-9 rounded-md border border-line px-2 font-mono text-sm">
            @error('vin') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-xs font-medium uppercase tracking-wide text-muted">Make</span>
            <input type="text" wire:model="make" class="h-9 rounded-md border border-line px-2 text-sm">
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-xs font-medium uppercase tracking-wide text-muted">Model</span>
            <input type="text" wire:model="model" class="h-9 rounded-md border border-line px-2 text-sm">
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-xs font-medium uppercase tracking-wide text-muted">Vehicle category</span>
            <select wire:model="vehicle_category" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
                @foreach ($categories as $category)
                    <option value="{{ $category->value }}">{{ $category->label() }}</option>
                @endforeach
            </select>
            @error('vehicle_category') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-xs font-medium uppercase tracking-wide text-muted">Licence expires on (YYYY-MM-DD)</span>
            <input type="date" wire:model="licence_expires_on" class="h-9 rounded-md border border-line px-2 font-mono text-sm">
            @error('licence_expires_on') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
        </label>
        <div class="sm:col-span-2 flex items-center justify-end gap-2">
            <a href="{{ route('fleet.review.queue') }}" class="rounded-md border border-line px-3 py-2 text-sm">Cancel</a>
            <button type="submit" class="rounded-md border border-line bg-ink px-3 py-2 text-sm text-white">Confirm and publish</button>
        </div>
    </form>
</div>
