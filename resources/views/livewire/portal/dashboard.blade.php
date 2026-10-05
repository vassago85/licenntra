<div>
    @php
        $toneClasses = [
            'info' => 'bg-blue-50 text-blue-900',
            'warning' => 'bg-amber-50 text-amber-900',
            'success' => 'bg-emerald-50 text-emerald-900',
            'danger' => 'bg-red-50 text-red-900',
            'neutral' => 'bg-gray-50 text-gray-700',
        ];
        $companyName = \App\Models\BrandingSetting::current()->company_name;
        $tileRows = [
            ['needsAction', 'Needs your action', true],
            ['withCompany', 'With '.$companyName, false],
            ['datafix', 'Datafix', false],
            ['ready', 'Ready for collection', false],
        ];
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold">Dashboard</h1>
            <p class="text-sm text-muted">
                {{ $now->translatedFormat('l j F Y') }} · {{ $openCount }} open application{{ $openCount === 1 ? '' : 's' }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <input wire:model.live.debounce.300ms="search" type="search"
                placeholder="Reference, VIN, NaTIS no, owner"
                class="h-10 w-72 rounded-md border border-line bg-white px-3 text-sm">
            <a href="{{ route('applications.create') }}"
                class="inline-flex h-10 items-center rounded-md px-4 text-sm font-semibold text-white"
                style="background: var(--brand)">New application</a>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($tileRows as $tileRow)
            @php
                $tileKey = $tileRow[0];
                $tileLabel = $tileRow[1];
                $tilePriority = $tileRow[2];
                $tile = $tiles[$tileKey];
            @endphp
            <section @class([
                'rounded-md border border-line bg-white px-4 py-4',
                'shadow-[inset_0_3px_0_#B3261E]' => $tilePriority,
            ])>
                <p class="text-sm text-muted">{{ $tileLabel }}</p>
                <p class="mt-1 font-mono text-3xl">{{ $tile['total'] }}</p>
                <p class="mt-1 min-h-[1rem] text-xs text-muted">
                    {{ $tile['parts'] ? implode(' · ', $tile['parts']) : 'All clear.' }}
                </p>
            </section>
        @endforeach
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
        <section class="overflow-hidden rounded-md border border-line bg-white">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                <h2 class="text-sm font-semibold">Applications</h2>
                <div class="flex flex-wrap gap-2">
                    @foreach ([
                        'open' => 'All open',
                        'needs_action' => 'Needs action',
                        'commercial' => 'Commercial',
                        'passenger' => 'Passenger',
                        'completed' => 'Completed'.($completedCount ? ' ('.$completedCount.')' : ''),
                    ] as $value => $label)
                        <button type="button" wire:click="setFilter('{{ $value }}')"
                            @class([
                                'h-8 rounded-full border px-3 text-xs',
                                'border-ink bg-ink text-white' => $filter === $value,
                                'border-line bg-white text-ink hover:border-ink' => $filter !== $value,
                            ])
                            aria-pressed="{{ $filter === $value ? 'true' : 'false' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[920px] border-collapse text-left text-sm">
                    <thead class="text-xs text-muted">
                        <tr>
                            <th class="px-3 py-2 font-medium">Reference</th>
                            <th class="px-3 py-2 font-medium">Vehicle / VIN</th>
                            <th class="px-3 py-2 font-medium">NaTIS no</th>
                            <th class="px-3 py-2 font-medium">Owner</th>
                            <th class="px-3 py-2 font-medium">Service</th>
                            <th class="px-3 py-2 font-medium">Stage</th>
                            <th class="px-3 py-2 font-medium">Datafix</th>
                            <th class="px-3 py-2 font-medium">Docs</th>
                            <th class="px-3 py-2 font-medium">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                $totalDocs = $row->documents->where('required', true)->count();
                                $acceptedDocs = $row->documents->where('status', \App\Enums\DocumentStatus::Accepted)->count();
                                $uploadedDocs = $row->documents->whereNotIn('status', [
                                    \App\Enums\DocumentStatus::Missing,
                                ])->count();
                                $datafixReady = in_array($row->datafix_status, [
                                    \App\Enums\DatafixStatus::Ready,
                                    \App\Enums\DatafixStatus::InProgress,
                                ], true);
                                $datafixColour = match ($row->datafix_status) {
                                    \App\Enums\DatafixStatus::NotRequired => '#F0F1EE',
                                    \App\Enums\DatafixStatus::AwaitingDocuments => '#E1E4DE',
                                    \App\Enums\DatafixStatus::Ready, \App\Enums\DatafixStatus::InProgress => '#146d61',
                                    \App\Enums\DatafixStatus::Completed => '#2E7D4F',
                                    \App\Enums\DatafixStatus::Queried => '#B3261E',
                                    default => '#E1E4DE',
                                };
                                $datafixLabel = match ($row->datafix_status) {
                                    \App\Enums\DatafixStatus::NotRequired => 'Not required',
                                    \App\Enums\DatafixStatus::AwaitingDocuments => 'Awaiting docs',
                                    \App\Enums\DatafixStatus::Ready => 'Ready',
                                    \App\Enums\DatafixStatus::InProgress => 'In progress',
                                    \App\Enums\DatafixStatus::Completed => 'Completed',
                                    \App\Enums\DatafixStatus::Queried => 'Queried',
                                    default => '—',
                                };
                                $reason = match ($row->stage) {
                                    \App\Enums\ApplicationStage::ChangesRequested => 'Rejected document',
                                    \App\Enums\ApplicationStage::QuoteSent => $row->quotes->first()?->expires_at?->format('j M'),
                                    \App\Enums\ApplicationStage::PaymentPending => 'Invoice awaiting payment',
                                    \App\Enums\ApplicationStage::SubmittedToAuthority => $row->submitted_at?->format('j M'),
                                    \App\Enums\ApplicationStage::Draft => $row->vehicle?->vin ? null : 'VIN missing',
                                    default => null,
                                };
                            @endphp
                            <tr class="border-t border-line align-top">
                                <td class="px-3 py-3">
                                    <a class="font-mono text-xs font-medium" href="{{ route('applications.show', $row) }}">{{ $row->reference }}</a>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="font-medium">{{ trim(($row->vehicle?->make ?? '').' '.($row->vehicle?->model ?? '')) ?: '—' }}</div>
                                    @if ($row->vehicle?->vin)
                                        <div class="mt-0.5 font-mono text-xs text-muted">{{ $row->vehicle->vin }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3 font-mono text-xs">{{ $row->vehicle?->vehicle_register_number ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <div>{{ $row->businessClient?->business_name ?? ($row->owner_type === \App\Enums\OwnerType::Business ? '—' : 'Individual') }}</div>
                                    <div class="text-xs text-muted">{{ $row->owner_type === \App\Enums\OwnerType::Business ? 'Business' : 'Individual' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">
                                    {{ $row->service_type === \App\Enums\ServiceType::RegisterAndLicense ? 'Register & license' : 'Register only' }}
                                </td>
                                <td class="px-3 py-3">
                                    <span class="inline-block whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium {{ $toneClasses[$row->stage->tone()] }}">
                                        {{ $row->stage->label() }}
                                    </span>
                                    @if ($reason)
                                        <div class="mt-1 text-xs text-muted">{{ $reason }}</div>
                                    @endif
                                    @if ($row->deliverables->count() > 0)
                                        <div class="mt-1 whitespace-nowrap text-xs text-emerald-900">{{ $row->deliverables->count() }} document{{ $row->deliverables->count() === 1 ? '' : 's' }} returned</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex gap-[3px]" aria-hidden="true">
                                        @for ($i = 0; $i < 4; $i++)
                                            <span class="block h-[6px] w-[14px] rounded-[1px]"
                                                style="background: {{ $i < ($acceptedDocs >= 4 ? 4 : max(0, (int) floor(($acceptedDocs / max($totalDocs, 1)) * 4))) ? '#2E7D4F' : $datafixColour }}"></span>
                                        @endfor
                                    </div>
                                    <div class="mt-1 whitespace-nowrap text-xs text-muted">{{ $datafixLabel }}</div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 font-mono text-xs">{{ $uploadedDocs }} / {{ $totalDocs ?: '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs text-muted">{{ $row->updated_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-6 text-center text-sm text-muted">No applications match this filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line px-3 py-2">{{ $rows->links() }}</div>
        </section>

        <aside class="flex flex-col gap-4">
            <section class="overflow-hidden rounded-md border border-line bg-white">
                <h2 class="border-b border-line px-4 py-3 text-sm font-semibold">Needs your action</h2>
                @forelse ($actionItems as $item)
                    <div class="flex flex-col gap-1 border-b border-line px-4 py-3 last:border-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-xs">{{ $item['ref'] }}</span>
                            <span class="text-xs font-medium
                                @switch($item['tone'])
                                    @case('danger') text-red-900 @break
                                    @case('warning') text-amber-900 @break
                                    @case('success') text-emerald-900 @break
                                    @default text-gray-700
                                @endswitch">{{ $item['label'] }}</span>
                        </div>
                        <p class="text-sm">{{ $item['note'] }}</p>
                        <a href="{{ $item['url'] }}" class="text-sm font-medium" style="color: var(--brand)">{{ $item['cta'] }}</a>
                    </div>
                @empty
                    <p class="px-4 py-6 text-sm text-muted">Nothing needs your attention. Clean slate.</p>
                @endforelse
            </section>

            <section class="overflow-hidden rounded-md border border-line bg-white">
                <h2 class="border-b border-line px-4 py-3 text-sm font-semibold">Business clients · retention</h2>
                @forelse ($retention as $client)
                    @php
                        $daysLeft = $client->retention_expires_at ? now()->diffInDays($client->retention_expires_at, false) : null;
                        $isExpiring = $daysLeft !== null && $daysLeft <= 30 && $daysLeft >= 0;
                    @endphp
                    <div class="flex flex-col gap-1 border-b border-line px-4 py-3 last:border-0">
                        <div class="text-sm font-medium">{{ $client->business_name }}</div>
                        <div class="text-xs {{ $isExpiring ? 'text-amber-900' : 'text-muted' }}">
                            @if ($client->legal_hold)
                                Legal hold · retained indefinitely
                            @elseif ($client->retention_expires_at)
                                Documents deleted on {{ $client->retention_expires_at->format('j M Y') }}
                                @if ($isExpiring)
                                    ({{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }})
                                @else
                                    · {{ $client->retention_period_months ?? '—' }} months
                                @endif
                            @else
                                No retention set
                            @endif
                        </div>
                        @if ($isExpiring)
                            <div class="flex gap-3 text-sm">
                                <a href="{{ route('business-clients.show', $client) }}" class="font-medium" style="color: var(--brand)">Extend</a>
                                <a href="{{ route('business-clients.show', $client) }}" class="text-muted">Let it lapse</a>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-6 text-sm text-muted">No business clients on file.</p>
                @endforelse
            </section>
        </aside>
    </div>
</div>
