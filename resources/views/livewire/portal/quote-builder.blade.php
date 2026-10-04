<div>
<div class="mb-4">
    <a href="{{ route('review.show', $application) }}" class="text-sm text-muted">{{ $application->reference }}</a>
    <h1 class="text-xl font-semibold">Quote</h1>
</div>

@if ($errors->any())
    <p class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm text-red-800">{{ $errors->first() }}</p>
@endif

<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_280px]">
    <section class="rounded-md border border-line bg-white p-3">
        <div class="space-y-3">
            @foreach ($lines as $index => $line)
                <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_120px_120px]" wire:key="line-{{ $index }}">
                    <input wire:model="lines.{{ $index }}.description" placeholder="Description" class="rounded-md border border-line px-2 py-2 text-sm">
                    <input wire:model="lines.{{ $index }}.client_rands" placeholder="Client price (R)" class="rounded-md border border-line px-2 py-2 text-sm">
                    <input wire:model="lines.{{ $index }}.internal_rands" placeholder="Internal cost (R)" class="rounded-md border border-line px-2 py-2 text-sm">
                </div>
            @endforeach
        </div>
        <button type="button" wire:click="addLine" class="mt-3 h-8 rounded-md border border-line px-2 text-sm">Add line</button>
        <label class="mt-4 block text-sm">Expires
            <input type="date" wire:model="expires_at" class="mt-1 rounded-md border border-line px-2 py-2">
        </label>
        <label class="mt-3 block text-sm">Client note
            <textarea wire:model="client_notes" rows="2" class="mt-1 w-full rounded-md border border-line px-2 py-2"></textarea>
        </label>
        <label class="mt-3 block rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Internal note
            <textarea wire:model="internal_notes" rows="2" class="mt-1 w-full rounded-md border border-line bg-white px-2 py-2"></textarea>
        </label>
        <button type="button" wire:click="send" class="mt-4 h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Send quote</button>
    </section>
    <aside class="rounded-md border border-line bg-white p-3 text-sm">
        <h2 class="font-semibold">Fee estimate</h2>
        <ul class="mt-2 space-y-1">
            @foreach ($snapshot['lines'] as $line)
                <li class="flex justify-between gap-2"><span>{{ $line['label'] }}</span><span class="font-mono">{{ $money::rands($line['amount_cents']) }}</span></li>
            @endforeach
        </ul>
    </aside>
</div>
</div>
