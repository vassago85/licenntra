<div>
<div class="mb-4 flex items-start justify-between gap-4">
    <div>
        <a href="{{ route('business-clients.index') }}" class="text-sm text-muted">Business clients</a>
        <h1 class="text-xl font-semibold">{{ $businessClient->business_name }}</h1>
        <p class="text-sm text-muted">{{ str_replace('_', ' ', $businessClient->usable_as) }} · {{ $businessClient->status }}</p>
    </div>
    @can('update', $businessClient)
        <a href="{{ route('business-clients.edit', $businessClient) }}"
           class="h-9 rounded-md border border-line bg-white px-3 text-sm font-semibold leading-9">Edit</a>
    @endcan
</div>

@if (session('status'))
    <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <p class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm text-red-800">{{ $errors->first() }}</p>
@endif

<div class="grid gap-4 lg:grid-cols-2">
    <section class="rounded-md border border-line bg-white p-3 text-sm">
        <h2 class="font-semibold">Details</h2>
        <dl class="mt-3 space-y-2">
            <div class="flex justify-between gap-3"><dt class="text-muted">Proxy</dt><dd>{{ $businessClient->proxy_name ?? '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted">Address</dt><dd>{{ $businessClient->address ?? '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted">Retention ends</dt><dd class="font-mono">{{ $businessClient->retention_expires_at?->format('d M Y') ?? 'Not set' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted">Legal hold</dt><dd>{{ $businessClient->legal_hold ? 'Yes' : 'No' }}</dd></div>
        </dl>
    </section>

    <section class="rounded-md border border-line bg-white p-3 text-sm">
        <h2 class="font-semibold">Retention consent</h2>
        <p class="mt-2 text-muted">{{ $settings->retention_wording }}</p>
        @can('update', $businessClient)
            <form wire:submit="saveConsent" class="mt-3 space-y-3">
                <label class="block">Period
                    <select wire:model="period_months" class="mt-1 h-9 w-full rounded-md border border-line px-2">
                        @foreach ($options as $months)
                            <option value="{{ $months }}">{{ $months }} months</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model="consent" class="mt-1">
                    <span>I confirm I have this business's authorisation to keep these documents for the selected period.</span>
                </label>
                <button type="submit" class="h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Save consent</button>
            </form>
        @else
            <p class="mt-3 rounded-md border border-line bg-paper px-2 py-2 text-xs text-muted">Only your dealership's client admin can record retention consent.</p>
        @endcan
        <ul class="mt-4 space-y-1 text-xs text-muted">
            @foreach ($consents as $consent)
                <li class="font-mono">{{ $consent->confirmed_at?->format('d M Y') }} · {{ $consent->period_months }} months · wording {{ $consent->wording_version }}</li>
            @endforeach
        </ul>
    </section>

    <section class="rounded-md border border-line bg-white p-3 text-sm lg:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="font-semibold">Documents</h2>
                <p class="text-xs text-muted">BRN certificate, proxy ID, proof of address, ID copy, and any supporting paperwork. Replacing a document keeps earlier versions on file for audit.</p>
            </div>
            @can('update', $businessClient)
                <span class="rounded-full border border-line bg-paper px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted">PDF / JPG / PNG · 15 MB max</span>
            @endcan
        </div>

        <ul class="mt-3 space-y-2">
            @forelse ($documents as $document)
                @php($version = $document->currentVersion)
                <li class="rounded-md border border-line bg-paper p-3" wire:key="bc-doc-{{ $document->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 space-y-0.5">
                            <p class="text-sm font-semibold">{{ $document->documentType?->name ?? 'Document' }}</p>
                            @if ($version)
                                <p class="text-xs text-muted">
                                    <span class="font-mono">{{ $version->original_filename }}</span>
                                    · {{ number_format(($version->size ?? 0) / 1024, 0) }} KB
                                    · uploaded {{ $version->created_at?->format('d M Y H:i') }}
                                    @if ($version->uploader) by {{ $version->uploader->name }} @endif
                                </p>
                            @else
                                <p class="text-xs text-muted">No file on record yet.</p>
                            @endif
                        </div>
                        @if ($version)
                            <a href="{{ route('documents.download', $version) }}" class="h-8 shrink-0 rounded-md border border-line bg-white px-3 text-xs font-semibold leading-8 hover:bg-paper">Download</a>
                        @endif
                    </div>

                    @can('update', $businessClient)
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <input type="file" wire:model="replacementFiles.{{ $document->id }}" accept=".pdf,.jpg,.jpeg,.png" class="min-w-0 text-xs">
                            <button type="button" wire:click="replaceDocument({{ $document->id }})" class="h-8 shrink-0 rounded-md border border-line bg-white px-3 text-xs font-semibold hover:bg-paper">
                                {{ $version ? 'Replace' : 'Upload' }}
                            </button>
                        </div>
                        @error('replacementFiles.'.$document->id)
                            <p class="mt-1 text-xs text-red-800">{{ $message }}</p>
                        @enderror
                    @endcan
                </li>
            @empty
                <li class="rounded-md border border-dashed border-line bg-paper p-3 text-center text-xs text-muted">No documents on file yet.</li>
            @endforelse
        </ul>

        @can('update', $businessClient)
            <div class="mt-4 rounded-md border border-dashed border-line bg-paper p-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">Add a document</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-[minmax(0,200px)_minmax(0,1fr)_auto]">
                    <select wire:model="uploadTypeCode" class="h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                        <option value="">Document type</option>
                        @foreach ($availableTypes as $type)
                            <option value="{{ $type->code }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                    <input type="file" wire:model="uploadFile" accept=".pdf,.jpg,.jpeg,.png" class="min-w-0 text-xs">
                    <button type="button" wire:click="uploadDocument" class="h-9 shrink-0 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Upload</button>
                </div>
                @error('uploadFile')
                    <p class="mt-1 text-xs text-red-800">{{ $message }}</p>
                @enderror
            </div>
        @endcan
    </section>
</div>
</div>
