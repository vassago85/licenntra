<div>
<div class="mb-4 flex items-end justify-between gap-4">
    <h1 class="text-xl font-semibold">Business clients</h1>
    <div class="flex items-center gap-2">
        <input wire:model.live.debounce.300ms="search" placeholder="Search" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
        @can('create', App\Models\BusinessClient::class)
            <a href="{{ route('business-clients.create') }}"
               class="h-9 rounded-md px-3 text-sm font-semibold leading-9 text-white"
               style="background: var(--brand)">+ Add client</a>
        @endcan
    </div>
</div>

@if (session('status'))
    <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
        {{ session('status') }}
    </div>
@endif

<section class="rounded-md border border-line bg-white">
    <table class="w-full text-left text-sm">
        <thead class="text-xs text-muted">
            <tr class="border-b border-line">
                <th class="px-3 py-2 font-medium">Business</th>
                <th class="px-3 py-2 font-medium">Use</th>
                <th class="px-3 py-2 font-medium">Retention</th>
                <th class="px-3 py-2 font-medium">Hold</th>
                <th class="px-3 py-2 text-right font-medium"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clients as $client)
                <tr class="border-b border-line last:border-0">
                    <td class="px-3 py-2"><a href="{{ route('business-clients.show', $client) }}" class="font-medium hover:underline">{{ $client->business_name }}</a></td>
                    <td class="px-3 py-2">{{ str_replace('_', ' ', $client->usable_as) }}</td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $client->retention_expires_at?->format('d M Y') ?? 'Not set' }}</td>
                    <td class="px-3 py-2">{{ $client->legal_hold ? 'Legal hold' : '—' }}</td>
                    <td class="px-3 py-2 text-right">
                        @can('update', $client)
                            <a href="{{ route('business-clients.edit', $client) }}" class="text-xs font-semibold text-ink hover:underline">Edit</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-3 py-6 text-center text-muted">
                        No business clients yet.
                        @can('create', App\Models\BusinessClient::class)
                            <a href="{{ route('business-clients.create') }}" class="font-semibold text-ink hover:underline">Add your first one</a>.
                        @endcan
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</section>
</div>
