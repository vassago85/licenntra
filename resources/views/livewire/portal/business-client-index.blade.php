<div>
<div class="mb-4 flex items-end justify-between gap-4">
    <h1 class="text-xl font-semibold">Business clients</h1>
    <input wire:model.live.debounce.300ms="search" placeholder="Search" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
</div>
<section class="rounded-md border border-line bg-white">
    <table class="w-full text-left text-sm">
        <thead class="text-xs text-muted">
            <tr class="border-b border-line">
                <th class="px-3 py-2 font-medium">Business</th>
                <th class="px-3 py-2 font-medium">Use</th>
                <th class="px-3 py-2 font-medium">Retention</th>
                <th class="px-3 py-2 font-medium">Hold</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clients as $client)
                <tr class="border-b border-line last:border-0">
                    <td class="px-3 py-2"><a href="{{ route('business-clients.show', $client) }}">{{ $client->business_name }}</a></td>
                    <td class="px-3 py-2">{{ str_replace('_', ' ', $client->usable_as) }}</td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $client->retention_expires_at?->format('d M Y') ?? 'Not set' }}</td>
                    <td class="px-3 py-2">{{ $client->legal_hold ? 'Legal hold' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-3 py-6 text-muted">No business clients yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
</div>
