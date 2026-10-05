<div>
<div class="mb-4 flex items-end justify-between gap-4">
    <div>
        <p class="font-mono text-xs text-muted">{{ $application->reference }} · {{ $application->clientAccount?->name }}</p>
        <h1 class="text-xl font-semibold">{{ $application->stage->label() }}</h1>
        @if ($application->dangerous_goods)
            <p class="text-sm font-semibold">Dangerous goods requested</p>
        @endif
    </div>
    @can('review', $application)
        <button type="button" wire:click="assignToMe" class="h-9 rounded-md border border-line bg-white px-3 text-sm">Assign to me</button>
    @endcan
</div>

@if ($errors->any())
    <p class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm text-red-800">{{ $errors->first() }}</p>
@endif

<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
    <div class="space-y-4">
        <section class="rounded-md border border-line bg-white">
            <h2 class="border-b border-line px-3 py-2 text-sm font-semibold">Documents</h2>
            <ul class="divide-y divide-line text-sm">
                @foreach ($application->documents as $document)
                    <li class="px-3 py-3" wire:key="review-doc-{{ $document->id }}">
                        <div class="flex items-center justify-between gap-3">
                            <span>{{ $document->label() }} @unless($document->required)<span class="text-muted">optional</span>@endunless</span>
                            <span class="font-mono text-xs">{{ $document->status->label() }}</span>
                        </div>
                        @if ($document->currentVersion && auth()->user()->can('download', $document))
                            <a class="text-xs" href="{{ route('documents.download', $document->currentVersion) }}">Open file</a>
                        @endif
                        @if ($document->currentVersion)
                            @php($version = $document->currentVersion)
                            @php($sizeKb = number_format($version->size / 1024, 0))
                            <div class="mt-2 rounded-md border border-line bg-paper px-2 py-2 text-xs">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="font-mono">
                                        {{ strtoupper(pathinfo($version->original_filename, PATHINFO_EXTENSION)) ?: 'FILE' }}
                                        · {{ $sizeKb }} KB
                                        @if ($version->page_count)
                                            · {{ $version->page_count }} {{ \Illuminate\Support\Str::plural('page', $version->page_count) }}
                                        @endif
                                    </span>
                                    @switch($version->scan_status)
                                        @case('clean')
                                            <span class="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-800">Virus scan clean</span>
                                            @break
                                        @case('infected')
                                            <span class="rounded border border-red-300 bg-red-50 px-1.5 py-0.5 text-[11px] text-red-800">Virus found</span>
                                            @break
                                        @case('pending')
                                            <span class="rounded border border-line bg-white px-1.5 py-0.5 text-[11px] text-muted">Scan pending</span>
                                            @break
                                        @default
                                            <span class="rounded border border-line bg-white px-1.5 py-0.5 text-[11px] text-muted">Scan {{ $version->scan_status }}</span>
                                    @endswitch
                                    @switch($version->inspection_status)
                                        @case('clean')
                                            <span class="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-800">Text readable</span>
                                            @break
                                        @case('empty')
                                            <span class="rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-900" title="{{ $version->inspection_notes }}">Image-only PDF</span>
                                            @break
                                        @case('unreadable')
                                            <span class="rounded border border-red-300 bg-red-50 px-1.5 py-0.5 text-[11px] text-red-800" title="{{ $version->inspection_notes }}">Could not parse</span>
                                            @break
                                        @case('skipped')
                                            <span class="rounded border border-line bg-white px-1.5 py-0.5 text-[11px] text-muted">Not a PDF</span>
                                            @break
                                        @default
                                            <span class="rounded border border-line bg-white px-1.5 py-0.5 text-[11px] text-muted">Inspection pending</span>
                                    @endswitch
                                </div>
                                @if ($version->text_excerpt)
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-[11px] text-muted">Show first 500 characters</summary>
                                        <pre class="mt-1 max-h-32 overflow-y-auto whitespace-pre-wrap break-words rounded border border-line bg-white p-2 font-mono text-[11px] leading-snug text-ink">{{ $version->text_excerpt }}</pre>
                                    </details>
                                @endif
                            </div>
                        @endif
                        @if ($document->requiresOriginal())
                            @if ($document->isOriginalReceived())
                                <p class="mt-2 text-xs font-semibold text-emerald-800">Original received {{ $document->original_received_at->format('d M Y H:i') }} - clear to forward to the licensing authority</p>
                            @else
                                <p class="mt-2 rounded-md border border-amber-300 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900">Awaiting original - do not forward to the licensing authority until the dealer confirms the physical copy is in hand</p>
                            @endif
                        @endif
                        @if ($application->dangerous_goods && $document->documentType?->code === 'cof')
                            @if ($document->dangerous_goods_stamped)
                                <p class="mt-2 text-xs font-semibold">Stamped Dangerous goods</p>
                            @else
                                <label class="mt-2 flex items-center gap-2 text-xs">
                                    <input type="checkbox" wire:model="dangerousGoodsStamped">
                                    Certificate of fitness is stamped Dangerous goods
                                </label>
                            @endif
                        @endif
                        @can('review', $document)
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="acceptDocument({{ $document->id }})" class="h-8 rounded-md border border-line px-2 text-xs">Accept</button>
                                <select wire:model="rejectionReasons.{{ $document->id }}" class="h-8 rounded-md border border-line px-2 text-xs">
                                    <option value="">Reason</option>
                                    @foreach ($reasons as $reason)
                                        <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                                    @endforeach
                                </select>
                                <input wire:model="rejectionComments.{{ $document->id }}" placeholder="Comment" class="h-8 rounded-md border border-line px-2 text-xs">
                                <button type="button" wire:click="rejectDocument({{ $document->id }})" class="h-8 rounded-md border border-line px-2 text-xs">Reject</button>
                            </div>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-md border border-line bg-white p-3 text-sm">
            <h2 class="font-semibold">Datafix · {{ $application->datafix_status->label() }}</h2>
            <p class="mt-1 text-xs text-muted">Client tare {{ $application->datafix?->client_tare_kg ?? $application->vehicle?->tare_kg ?? '—' }}, body {{ $application->datafix?->client_body_type ?? $application->vehicle?->body_type ?? '—' }}, GVM {{ $application->datafix?->client_gvm_kg ?? $application->vehicle?->gvm_kg ?? '—' }}</p>
            <div class="mt-3 grid gap-2 sm:grid-cols-3">
                <label>Tare kg <input wire:model="tare_kg" class="mt-1 w-full rounded-md border border-line px-2 py-1"></label>
                <label>Body <input wire:model="body_type" class="mt-1 w-full rounded-md border border-line px-2 py-1"></label>
                <label>GVM kg <input wire:model="gvm_kg" class="mt-1 w-full rounded-md border border-line px-2 py-1"></label>
            </div>
            <label class="mt-2 block text-xs text-muted">If the client value differs
                <select wire:model="value_choice" class="mt-1 h-8 rounded-md border border-line px-2 text-sm">
                    <option value="">Choose</option>
                    <option value="client">Use the client value</option>
                    <option value="reviewer">Use the reviewer value</option>
                </select>
            </label>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="confirmDatafix" class="h-8 rounded-md border border-line px-2 text-xs">Confirm values</button>
                <input wire:model="authority_reference" placeholder="Authority reference" class="h-8 rounded-md border border-line px-2 text-xs">
                <button type="button" wire:click="completeDatafix" class="h-8 rounded-md border border-line px-2 text-xs">Complete datafix</button>
            </div>
            <div class="mt-3 flex gap-2">
                <input wire:model="query_note" placeholder="Query note" class="h-8 flex-1 rounded-md border border-line px-2 text-xs">
                <button type="button" wire:click="queryDatafix" class="h-8 rounded-md border border-line px-2 text-xs">Query</button>
            </div>
        </section>

        <section class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            <h2 class="font-semibold">Internal notes</h2>
            <p class="text-xs text-muted">Hidden from the client.</p>
            <ul class="mt-2 space-y-2">
                @foreach ($application->notes->where('visibility', 'internal') as $note)
                    <li>{{ $note->body }} <span class="text-xs text-muted">{{ $note->author?->name }}</span></li>
                @endforeach
            </ul>
            <textarea wire:model="internalNote" rows="2" class="mt-2 w-full rounded-md border border-line bg-white px-2 py-1"></textarea>
            <button type="button" wire:click="addNote('internal')" class="mt-2 h-8 rounded-md border border-line bg-white px-2 text-xs">Save internal note</button>
        </section>

        <section class="rounded-md border border-line bg-white p-3 text-sm">
            <h2 class="font-semibold">Client notes</h2>
            <ul class="mt-2 space-y-2">
                @foreach ($application->notes->where('visibility', 'client') as $note)
                    <li>{{ $note->body }}</li>
                @endforeach
            </ul>
            <textarea wire:model="clientNote" rows="2" class="mt-2 w-full rounded-md border border-line px-2 py-1"></textarea>
            <button type="button" wire:click="addNote('client')" class="mt-2 h-8 rounded-md border border-line px-2 text-xs">Save client note</button>
        </section>
    </div>

    <aside class="space-y-4">
        <section class="rounded-md border border-line bg-white p-3 text-sm">
            <h2 class="font-semibold">Fee snapshot</h2>
            @php($snapshot = $application->fee_snapshot)
            @if ($snapshot)
                <ul class="mt-2 space-y-1">
                    @foreach ($snapshot['lines'] as $line)
                        <li class="flex justify-between gap-2"><span>{{ $line['label'] }}</span><span class="font-mono">{{ $money::rands($line['amount_cents']) }}</span></li>
                    @endforeach
                </ul>
                <p class="mt-2 font-semibold">{{ $money::rands($snapshot['total_cents']) }}</p>
            @else
                <p class="mt-2 text-muted">Snapshot is taken when payment is requested.</p>
            @endif
            <div class="mt-3 space-y-2">
                <select wire:model="service_type" class="h-8 w-full rounded-md border border-line px-2 text-xs">
                    @foreach ($services as $service)
                        <option value="{{ $service->value }}">{{ $service->label() }}</option>
                    @endforeach
                </select>
                <input wire:model="service_reason" placeholder="Reason for the service change" class="h-8 w-full rounded-md border border-line px-2 text-xs">
                <button type="button" wire:click="changeService" class="h-8 rounded-md border border-line px-2 text-xs">Change service type</button>
            </div>
            @can('review', $application)
                <a href="{{ route('applications.quote', $application) }}" class="mt-3 inline-block text-sm">Open quote builder</a>
            @endcan
        </section>

        <section class="rounded-md border border-line bg-white p-3 text-sm">
            <h2 class="font-semibold">Move stage</h2>
            <textarea wire:model="reason" rows="2" placeholder="Reason, when the stage needs one" class="mt-2 w-full rounded-md border border-line px-2 py-1"></textarea>
            <div class="mt-2 flex flex-col gap-2">
                @foreach ($application->stage->successors() as $next)
                    @continue(in_array($next, [\App\Enums\ApplicationStage::QuoteAccepted, \App\Enums\ApplicationStage::PaymentVerified], true))
                    <button type="button" wire:click="advance('{{ $next->value }}')" class="h-8 rounded-md border border-line px-2 text-left text-xs">{{ $next->label() }}</button>
                @endforeach
            </div>
        </section>

        <section class="rounded-md border border-line bg-white p-3 text-sm">
            <h2 class="font-semibold">Audit trail</h2>
            <ul class="mt-2 space-y-2">
                @foreach ($audit as $event)
                    <li>
                        <p>{{ $event->summary }}</p>
                        <p class="font-mono text-xs text-muted">{{ $event->occurred_at?->format('d M H:i') }} · {{ $event->action }}</p>
                    </li>
                @endforeach
                @foreach ($application->stageHistories->sortByDesc('id') as $history)
                    <li>
                        <p>{{ $history->from_stage?->label() }} to {{ $history->to_stage->label() }}</p>
                        <p class="text-xs text-muted">{{ $history->reason }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    </aside>
</div>
</div>
