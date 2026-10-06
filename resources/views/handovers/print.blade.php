@php
    $title = $handover->direction->documentTitle() . ' - #' . $handover->id;
@endphp

<x-layouts.print :title="$title" :branding="$branding">
    <div class="print-controls">
        <button type="button" onclick="printPage()">Print this page</button>
    </div>

    <div class="brand-row">
        <div>
            <div class="brand-name">{{ $branding->company_name ?? 'Licentra' }}</div>
            <div class="doc-sub">{{ $handover->clientAccount?->name }}</div>
        </div>
        <div style="text-align: right;">
            <div class="doc-title">{{ $handover->direction->documentTitle() }}</div>
            <div class="doc-sub">#{{ $handover->id }} - {{ now()->format('d M Y') }}</div>
            @if ($handover->isCompleted())
                <span class="status-chip completed">Completed {{ $handover->confirmed_at?->format('d M Y H:i') }}</span>
            @else
                <span class="status-chip pending">Pending - sign below</span>
            @endif
        </div>
    </div>

    <div class="grid-2">
        <div class="box">
            <h3>Licensing company</h3>
            <div><strong>{{ $handover->counterparty_company ?: ($branding->company_name ?? 'Licentra') }}</strong></div>
            <div>{{ $handover->counterparty_name ?: '—' }}</div>
            @if ($handover->counterparty_identifier)
                <div style="font-family: ui-monospace, Menlo, monospace; font-size: 10pt;">ID / Emp #: {{ $handover->counterparty_identifier }}</div>
            @endif
        </div>
        <div class="box">
            <h3>Dealership</h3>
            <div><strong>{{ $handover->clientAccount?->name }}</strong></div>
            <div>Counter: {{ $handover->dealer_person_name ?: '—' }}</div>
            <div style="font-size: 10pt; color: #555;">Prepared by {{ $handover->createdBy?->name ?? '—' }} on {{ $handover->created_at->format('d M Y H:i') }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 24%;">Reference</th>
                <th style="width: 24%;">Vehicle</th>
                <th>Items handed over</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($handover->applications as $application)
                <tr>
                    <td style="font-family: ui-monospace, Menlo, monospace;">{{ $application->reference }}</td>
                    <td>
                        {{ $application->vehicle?->vehicle_register_number ?? '—' }}
                        @if ($application->vehicle?->make)
                            <div style="font-size: 9.5pt; color: #555;">{{ $application->vehicle->make }} {{ $application->vehicle->model }}</div>
                        @endif
                    </td>
                    <td>{{ $application->pivot->item_description ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="text-align: center; color: #555;">No applications linked to this hand-over.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($handover->items_summary)
        <div style="margin-top: 16px;">
            <strong style="font-size: 10pt; text-transform: uppercase; letter-spacing: 0.05em; color: #555;">Items summary</strong>
            <div class="narrative">{{ $handover->items_summary }}</div>
        </div>
    @endif

    <div class="signatures">
        <div class="sig-block">
            <div class="sig-label">Signed on behalf of the dealership</div>
            <div class="sig-name">{{ $handover->dealer_person_name ?: '____________________' }}</div>
            <div style="margin-top: 6px; font-size: 9pt; color: #555;">Date: ____________________</div>
        </div>
        <div class="sig-block">
            <div class="sig-label">Signed on behalf of the licensing company</div>
            <div class="sig-name">{{ $handover->counterparty_name ?: '____________________' }}</div>
            <div style="margin-top: 6px; font-size: 9pt; color: #555;">Date: ____________________</div>
        </div>
    </div>

    <div style="margin-top: 20px; font-size: 9pt; color: #555; text-align: center;">
        {{ $handover->direction === \App\Enums\HandoverDirection::Delivery ? 'The dealership acknowledges receipt of the documents listed above.' : 'The licensing company confirms collection of the documents listed above from the dealership.' }}
    </div>
</x-layouts.print>
