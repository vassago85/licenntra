@php
    $title = $packs->count() === 1
        ? 'Submission pack - '.$packs->first()['application']->reference
        : 'Submission packs - '.$packs->count().' applications';
    $pdfCount = $packs->sum(fn (array $entry): int => collect($entry['pack']?->manifest['documents'] ?? [])->where('mime', 'application/pdf')->count());
@endphp

<x-layouts.print :title="$title" :branding="$branding">
    <style>
        .pack + .pack { page-break-before: always; break-before: page; margin-top: 48px; padding-top: 24px; border-top: 4px solid #111; }
        .doc-page { page-break-before: always; break-before: page; margin-top: 32px; padding-top: 16px; border-top: 1px dashed #bbb; }
        .doc-page header { display: flex; justify-content: space-between; gap: 12px; font-size: 9.5pt; color: #555; border-bottom: 1px solid #ddd; padding-bottom: 6px; margin-bottom: 10px; }
        .doc-page img { display: block; max-width: 100%; max-height: 245mm; margin: 0 auto; }
        .doc-page iframe { width: 100%; height: 900px; border: 1px solid #ddd; }
        .pdf-note { border: 1px solid #e8c777; background: #fff8e6; padding: 10px 12px; border-radius: 4px; font-size: 10.5pt; }
        .tick { display: inline-block; width: 14px; height: 14px; border: 1px solid #111; vertical-align: middle; }
        .mono { font-family: ui-monospace, Menlo, monospace; font-size: 9.5pt; }
        .muted { color: #555; }
        .warn { border: 1px solid #e8c777; background: #fff8e6; padding: 8px 10px; border-radius: 4px; margin-top: 12px; font-size: 10.5pt; }
        .print-controls a { margin-right: 8px; font-size: 10pt; }
        .print-only { display: none; }
        @media print {
            .screen-only { display: none !important; }
            .print-only { display: block; }
            .pack + .pack { margin-top: 0; padding-top: 0; border-top: 0; }
            .doc-page { margin-top: 0; padding-top: 0; border-top: 0; }
        }
    </style>

    <div class="print-controls">
        <a href="{{ route('tasks.outstanding', ['tab' => 'submission_packs']) }}">Back to submission packs</a>
        <button type="button" onclick="printPage()">Print {{ $packs->count() === 1 ? 'pack' : $packs->count().' packs' }}</button>
    </div>

    @if ($pdfCount > 0)
        <div class="warn screen-only">
            {{ $pdfCount }} {{ \Illuminate\Support\Str::plural('document', $pdfCount) }} {{ $pdfCount === 1 ? 'is a PDF' : 'are PDFs' }}. Browsers cannot print an embedded PDF as part of this page, so each one has its own <strong>Open PDF to print</strong> link below. Print those and slot them in behind the matching divider page.
        </div>
    @endif

    @foreach ($packs as $entry)
        @php
            $application = $entry['application'];
            $pack = $entry['pack'];
            $versions = $entry['versions'];
            $vehicle = $application->vehicle;
            $documents = $pack?->manifest['documents'] ?? [];
        @endphp

        <section class="pack">
            <div class="brand-row">
                <div>
                    <div class="brand-name">{{ $branding->company_name ?? 'Licentra' }}</div>
                    <div class="doc-sub">For lodgement with the {{ $application->province?->label() ?? 'licensing' }} licensing department</div>
                </div>
                <div style="text-align: right;">
                    <div class="doc-title">{{ $application->stage === \App\Enums\ApplicationStage::AuthorityQuery ? 'Resubmission pack' : 'Submission pack' }}</div>
                    <div class="doc-sub mono">{{ $application->reference }}@if ($pack) · pack #{{ $pack->id }}@endif</div>
                    @if ($pack)
                        <div class="doc-sub">Prepared {{ $pack->created_at->format('d M Y H:i') }} by {{ $pack->preparedBy?->name ?? 'unknown' }}</div>
                    @endif
                </div>
            </div>

            @if ($pack === null)
                <p class="warn">No pack has been prepared for {{ $application->reference }} yet. Prepare it from the submission packs list or the application's review screen.</p>
            @else
                <div class="grid-2">
                    <div class="box">
                        <h3>Application</h3>
                        <div><strong>{{ $application->request_type?->label() ?? '—' }}</strong>@if ($application->service_type) · {{ $application->service_type->label() }}@endif</div>
                        <div>Customer: {{ $application->clientAccount?->name ?? '—' }}</div>
                        @if ($application->businessClient)
                            <div>Owner: {{ $application->businessClient->business_name }}</div>
                        @endif
                        @if ($application->titleHolder)
                            <div>Title holder: {{ $application->titleHolder->business_name }}</div>
                        @endif
                        <div>Province: {{ $application->province?->label() ?? '—' }}</div>
                        @if ($entry['natisForm'])
                            <div>{{ $entry['natisForm']['type']->formNumber() }}: {{ $entry['natisForm']['isChecked'] ? 'checked by '.($application->natisFormCheckedBy?->name ?? 'unknown').', printed behind this sheet' : 'not checked by operations yet' }}</div>
                        @endif
                        @if ($application->authority_reference && $application->stage === \App\Enums\ApplicationStage::AuthorityQuery)
                            <div>Department reference: <span class="mono">{{ $application->authority_reference }}</span></div>
                        @endif
                    </div>
                    <div class="box">
                        <h3>Vehicle</h3>
                        <div class="mono">VIN {{ $vehicle?->vin ?? '—' }}</div>
                        <div class="mono">Register no. {{ $vehicle?->vehicle_register_number ?? '—' }}</div>
                        <div>{{ trim(($vehicle?->make ?? '').' '.($vehicle?->model ?? '')) ?: '—' }}@if ($vehicle?->year) ({{ $vehicle->year }})@endif</div>
                        <div>Tare {{ $vehicle?->tare_kg ? number_format($vehicle->tare_kg).' kg' : '—' }} · GVM {{ $vehicle?->gvm_kg ? number_format($vehicle->gvm_kg).' kg' : '—' }}</div>
                    </div>
                </div>

                @if ($application->stage === \App\Enums\ApplicationStage::AuthorityQuery)
                    <div class="box" style="margin-top: 12px;">
                        <h3>Department query and resolution</h3>
                        <div><strong>Query:</strong> {{ $entry['queryNote'] ?? '—' }}</div>
                        <div><strong>Resolved:</strong> {{ $application->authority_query_resolution ?? '—' }}</div>
                    </div>
                @endif

                <table>
                    <thead>
                        <tr>
                            <th style="width: 4%;">#</th>
                            <th>Document</th>
                            <th style="width: 28%;">File (version on record)</th>
                            <th style="width: 18%;">Original</th>
                            <th style="width: 9%;">In pack</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documents as $index => $document)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>
                                    {{ $document['label'] }}
                                    @unless ($document['required'])<span class="muted"> (optional)</span>@endunless
                                </td>
                                <td>
                                    <div style="word-break: break-all;">{{ $document['original_filename'] }}</div>
                                    <div class="mono muted">v{{ $document['version_id'] }} · {{ substr($document['sha256'], 0, 12) }}</div>
                                </td>
                                <td>
                                    @if ($document['requires_original'])
                                        Original, received {{ $document['original_received_at'] ? \Illuminate\Support\Carbon::parse($document['original_received_at'])->format('d M Y') : '—' }}
                                    @else
                                        Copy
                                    @endif
                                </td>
                                <td style="text-align: center;"><span class="tick"></span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted" style="text-align: center;">No accepted documents in this pack.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="signatures">
                    <div class="sig-block">
                        <div class="sig-label">Prepared and checked by</div>
                        <div class="sig-name">{{ $pack->preparedBy?->name ?? '____________________' }}</div>
                        <div style="margin-top: 6px; font-size: 9pt;" class="muted">Signature / date: ____________________</div>
                    </div>
                    <div class="sig-block">
                        <div class="sig-label">Received by the licensing department</div>
                        <div class="sig-name">____________________</div>
                        <div style="margin-top: 6px; font-size: 9pt;" class="muted">Reference: ______________ Date / stamp: ______________</div>
                    </div>
                </div>

                @if ($entry['natisForm'])
                    @include('natis-forms.form', ['natisForm' => $entry['natisForm']])
                @endif

                @foreach ($documents as $index => $document)
                    @php($version = $versions->get($document['version_id']))
                    <div class="doc-page">
                        <header>
                            <span class="mono">{{ $application->reference }} · {{ $index + 1 }}/{{ count($documents) }}</span>
                            <strong>{{ $document['label'] }}</strong>
                            <span>{{ $document['requires_original'] ? 'Original attached' : 'Copy' }}</span>
                        </header>
                        @if ($version === null)
                            <p class="warn">This version is no longer on file.</p>
                        @elseif (str_starts_with($document['mime'], 'image/'))
                            <img src="{{ route('documents.download', ['version' => $version->id, 'inline' => 1]) }}" alt="{{ $document['label'] }}">
                        @else
                            <div class="pdf-note">
                                <strong>{{ $document['original_filename'] }}</strong> is a PDF.
                                <span class="print-only">Print it separately and place it behind this page.</span>
                                <a class="screen-only" href="{{ route('documents.download', ['version' => $version->id, 'inline' => 1]) }}" target="_blank" rel="noopener">Open PDF to print</a>
                            </div>
                            <iframe class="screen-only" src="{{ route('documents.download', ['version' => $version->id, 'inline' => 1]) }}" title="{{ $document['label'] }}"></iframe>
                        @endif
                    </div>
                @endforeach
            @endif
        </section>
    @endforeach
</x-layouts.print>
