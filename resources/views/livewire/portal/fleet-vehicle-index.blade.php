<div>
    <div class="mb-4 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold">Fleet vehicles</h1>
            <p class="mt-1 text-sm text-muted">
                {{ $activeCount }} active {{ \Illuminate\Support\Str::plural('vehicle', $activeCount) }} on file.
                A monthly email on the first of every month lists the vehicles whose licence expires that month.
            </p>
        </div>
        @if ($retiredCount > 0)
            <button type="button" wire:click="$toggle('showRetired')" class="h-9 rounded-md border border-line bg-white px-3 text-sm">
                {{ $showRetired ? 'Hide retired' : 'Show retired ('.$retiredCount.')' }}
            </button>
        @endif
    </div>

    @if ($activeCount === 0)
        <section class="rounded-md border border-dashed border-line bg-white px-4 py-10 text-center text-sm text-muted">
            Your licensing company adds vehicles to this list once they confirm each uploaded licence. Nothing to see yet.
        </section>
    @endif

    @foreach ($grouped as $monthLabel => $vehicles)
        <section class="mb-5 rounded-md border border-line bg-white">
            <header class="flex items-center justify-between gap-3 border-b border-line px-3 py-2">
                <h2 class="text-sm font-semibold">
                    {{ $monthLabel }}
                    @if ($monthLabel === 'Overdue')
                        <span class="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">Needs attention</span>
                    @endif
                </h2>
                <span class="text-xs text-muted">{{ $vehicles->count() }} {{ \Illuminate\Support\Str::plural('vehicle', $vehicles->count()) }}</span>
            </header>
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-muted">
                    <tr class="border-b border-line">
                        <th class="px-3 py-2 font-medium">Register</th>
                        <th class="px-3 py-2 font-medium">VIN</th>
                        <th class="px-3 py-2 font-medium">Make / Model</th>
                        <th class="px-3 py-2 font-medium">Category</th>
                        <th class="px-3 py-2 font-medium">Expires</th>
                        <th class="px-3 py-2 font-medium">Source</th>
                        <th class="px-3 py-2 font-medium text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($vehicles as $vehicle)
                        @php
                            $latestDoc = $vehicle->documents->firstWhere('confirmed_at', '!=', null);
                        @endphp
                        <tr class="border-b border-line last:border-0">
                            <td class="px-3 py-2 font-mono text-xs">{{ $vehicle->vehicle_register_number ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $vehicle->vin ?? '—' }}</td>
                            <td class="px-3 py-2">{{ trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $vehicle->vehicle_category?->label() ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $vehicle->licence_expires_on?->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">{{ $vehicle->licence_expiry_source?->label() ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if ($latestDoc?->documentVersion)
                                        <a href="{{ route('documents.download', $latestDoc->documentVersion) }}" class="text-xs underline">Licence</a>
                                    @endif
                                    <a href="{{ route('applications.create', ['prefill_fleet_vehicle' => $vehicle->id]) }}" class="rounded-md border border-line px-2 py-1 text-xs">Renew</a>
                                    <button type="button" wire:click="retire({{ $vehicle->id }})" wire:confirm="Retire this vehicle? It will stop appearing in renewal reminders." class="text-xs text-muted hover:underline">Retire</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endforeach

    @if ($showRetired && $retired->isNotEmpty())
        <section class="mb-5 rounded-md border border-line bg-paper">
            <header class="border-b border-line px-3 py-2">
                <h2 class="text-sm font-semibold text-muted">Retired</h2>
            </header>
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-muted">
                    <tr class="border-b border-line">
                        <th class="px-3 py-2 font-medium">Register</th>
                        <th class="px-3 py-2 font-medium">VIN</th>
                        <th class="px-3 py-2 font-medium">Make / Model</th>
                        <th class="px-3 py-2 font-medium">Retired on</th>
                        <th class="px-3 py-2 font-medium text-right">Licence</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($retired as $vehicle)
                        @php $latestDoc = $vehicle->documents->firstWhere('confirmed_at', '!=', null); @endphp
                        <tr class="border-b border-line last:border-0">
                            <td class="px-3 py-2 font-mono text-xs">{{ $vehicle->vehicle_register_number ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $vehicle->vin ?? '—' }}</td>
                            <td class="px-3 py-2">{{ trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) ?: '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $vehicle->retired_at?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-right">
                                @if ($latestDoc?->documentVersion)
                                    <a href="{{ route('documents.download', $latestDoc->documentVersion) }}" class="text-xs underline">Download</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
