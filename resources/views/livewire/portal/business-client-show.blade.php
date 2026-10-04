<div>
<div class="mb-4">
    <a href="{{ route('business-clients.index') }}" class="text-sm text-muted">Business clients</a>
    <h1 class="text-xl font-semibold">{{ $businessClient->business_name }}</h1>
    <p class="text-sm text-muted">{{ str_replace('_', ' ', $businessClient->usable_as) }} · {{ $businessClient->status }}</p>
</div>

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
        <ul class="mt-4 space-y-1 text-xs text-muted">
            @foreach ($consents as $consent)
                <li class="font-mono">{{ $consent->confirmed_at?->format('d M Y') }} · {{ $consent->period_months }} months · wording {{ $consent->wording_version }}</li>
            @endforeach
        </ul>
    </section>
</div>
</div>
