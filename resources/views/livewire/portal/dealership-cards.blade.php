<div>
    @php
        $toneClasses = [
            'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'danger' => 'bg-red-50 text-red-800 ring-red-200',
            'success' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            'info' => 'bg-sky-50 text-sky-800 ring-sky-200',
            'neutral' => 'bg-paper text-ink ring-line',
        ];
    @endphp

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">{{ $heading }}</h1>
            @if ($subheading)
                <p class="text-sm text-muted">{{ $subheading }}</p>
            @else
                <p class="text-sm text-muted">Pick a dealership to see its active applications as cards or a dense table.</p>
            @endif
        </div>
    </div>

    @if (session('status'))
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ session('status') }}</p>
    @endif

    {{-- Dealership picker + filter bar --}}
    <div class="mb-4 rounded-md border border-line bg-white p-3">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[14rem] flex-1">
                <label for="dealership-picker" class="block text-xs font-medium text-muted">Dealership</label>
                <select
                    id="dealership-picker"
                    wire:model.live="accountId"
                    class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                    <option value="">— Pick a dealership —</option>
                    @foreach ($allDealerships as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[14rem] flex-1">
                <label for="card-search" class="block text-xs font-medium text-muted">Registration or VIN</label>
                <input id="card-search" type="search" wire:model.live.debounce.300ms="search"
                    placeholder="e.g. TLX123G or last 6 of VIN"
                    class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
            </div>
            <div class="w-full sm:w-48">
                <label for="card-stage" class="block text-xs font-medium text-muted">Stage</label>
                <select id="card-stage" wire:model.live="stage"
                    class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                    <option value="">Any stage</option>
                    @foreach ($stageOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex h-9 shrink-0 items-center gap-4">
                <div class="flex items-center gap-4">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="overdue" class="rounded border-line">
                        Overdue
                    </label>
                    @if ($reviewerPicker)
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model.live="mine" class="rounded border-line">
                            Mine
                        </label>
                    @endif
                </div>
                <div class="inline-flex rounded-md border border-line bg-paper p-0.5 text-xs" role="group" aria-label="View mode">
                    @foreach (['cards' => 'Cards', 'table' => 'Table'] as $key => $label)
                        @php $active = $viewMode === $key; @endphp
                        <button type="button" wire:click="$set('viewMode', '{{ $key }}')"
                            aria-pressed="{{ $active ? 'true' : 'false' }}"
                            class="rounded px-2.5 py-1 font-medium transition {{ $active ? 'bg-white text-ink shadow-sm ring-1 ring-line' : 'text-muted hover:text-ink' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    @if ($account === null)
        <div class="rounded-md border border-dashed border-line bg-white p-10 text-center text-sm text-muted">
            Pick a dealership above to see its active applications.
        </div>
    @else
        {{-- Summary strip --}}
        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
            <div class="rounded-md border border-line bg-white p-3">
                <div class="text-xs text-muted">Active applications</div>
                <div class="font-mono text-xl">{{ $summary['total'] }}</div>
            </div>
            <div class="rounded-md border border-line bg-white p-3">
                <div class="text-xs text-muted">Overdue</div>
                <div class="font-mono text-xl {{ $summary['overdue'] > 0 ? 'text-red-700' : '' }}">{{ $summary['overdue'] }}</div>
            </div>
            <div class="rounded-md border border-line bg-white p-3">
                <div class="text-xs text-muted">Payments owed</div>
                <div class="font-mono text-xl">{{ $summary['payment_owed'] }}</div>
            </div>
            <div class="rounded-md border border-line bg-white p-3">
                <div class="text-xs text-muted">To verify</div>
                <div class="font-mono text-xl">{{ $summary['payment_awaiting_verification'] }}</div>
            </div>
            <div class="rounded-md border border-line bg-white p-3">
                <div class="text-xs text-muted">Ready for authority</div>
                <div class="font-mono text-xl">{{ $summary['ready_for_authority'] }}</div>
            </div>
        </div>

        @if ($cards->isEmpty())
            <div class="rounded-md border border-dashed border-line bg-white p-10 text-center text-sm text-muted">
                No active applications match these filters.
            </div>
        @elseif ($viewMode === 'table')
            <div class="overflow-x-auto rounded-md border border-line bg-white">
                <table class="min-w-full divide-y divide-line">
                    <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">Registration / VIN</th>
                            <th class="hidden xl:table-cell px-3 py-2 text-left font-medium">Application</th>
                            <th class="px-3 py-2 text-left font-medium">Stage</th>
                            <th class="hidden lg:table-cell px-3 py-2 text-left font-medium">Docs</th>
                            <th class="hidden lg:table-cell px-3 py-2 text-left font-medium">Payment</th>
                            <th class="hidden xl:table-cell px-3 py-2 text-left font-medium">Assigned to</th>
                            <th class="px-3 py-2 text-left font-medium">Due</th>
                            <th class="px-3 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($cards as $card)
                            @php
                                $tone = $toneClasses[$card['stage_tone']] ?? $toneClasses['neutral'];
                                $actionAllowed = match ($card['next_action_key']) {
                                    'review_docs' => $canReviewDocuments,
                                    'verify_payment' => $canVerifyPayments,
                                    'submit_authority', 'prepare_pack' => $canSubmitToAuthority,
                                    'open_handover' => $canReviewDocuments,
                                    'open_quote' => $canBuildQuotes,
                                    default => true,
                                };
                            @endphp
                            <tr class="{{ $card['overdue'] ? 'bg-red-50/60' : '' }} hover:bg-paper">
                                <td class="px-3 py-2 text-sm">
                                    <div class="font-mono font-semibold">{{ $card['vehicle_registration'] ?? '— no rego —' }}</div>
                                    @if ($card['vehicle_vin'])
                                        <div class="font-mono text-xs text-muted">VIN {{ \Illuminate\Support\Str::of($card['vehicle_vin'])->substr(-6) }}</div>
                                    @endif
                                </td>
                                <td class="hidden xl:table-cell whitespace-nowrap px-3 py-2 text-xs">
                                    <div class="font-mono">{{ $card['application']->reference }}</div>
                                    <div class="text-muted">{{ $card['request_type_label'] }}</div>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 font-medium ring-1 ring-inset {{ $tone }}">{{ $card['stage_label'] }}</span>
                                </td>
                                <td class="hidden lg:table-cell whitespace-nowrap px-3 py-2 text-xs">
                                    @if ($card['documents_required'] > 0)
                                        {{ $card['documents_accepted'] }}/{{ $card['documents_required'] }}
                                        @if ($card['documents_rejected'] > 0)
                                            <span class="text-red-700">· {{ $card['documents_rejected'] }} rejected</span>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="hidden lg:table-cell whitespace-nowrap px-3 py-2 text-xs">
                                    @if ($card['payment_on_statement'] ?? false)
                                        <span class="text-sky-700">On statement</span>
                                    @elseif ($card['payment_owed'])
                                        <span class="text-sky-700">Owed</span>
                                    @elseif ($card['payment_awaiting_verification'])
                                        <span class="text-amber-700">To verify</span>
                                    @elseif ($card['payment_verified'])
                                        <span class="text-emerald-700">Verified</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="hidden xl:table-cell whitespace-nowrap px-3 py-2 text-xs">{{ $card['reviewer']?->name ?? 'Unassigned' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs">
                                    @if ($card['due_at'])
                                        <span class="{{ $card['overdue'] ? 'font-semibold text-red-700' : 'text-muted' }}">{{ $card['due_at']->format('d M H:i') }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right text-xs">
                                    @if ($actionAllowed && $card['next_action_key'] === 'submit_authority')
                                        <button type="button" wire:click="openSubmitModal({{ $card['application']->id }})"
                                            class="inline-flex items-center rounded-md px-2.5 py-1 font-medium text-white"
                                            style="background: var(--brand);">
                                            Submit to authority
                                        </button>
                                    @elseif ($actionAllowed && $card['next_action_url'])
                                        <a href="{{ $card['next_action_url'] }}"
                                            class="inline-flex items-center rounded-md border border-line bg-white px-2.5 py-1 font-medium hover:bg-paper">
                                            {{ $card['next_action_label'] }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($cards as $card)
                    @php
                        $tone = $toneClasses[$card['stage_tone']] ?? $toneClasses['neutral'];
                        $actionAllowed = match ($card['next_action_key']) {
                            'review_docs' => $canReviewDocuments,
                            'verify_payment' => $canVerifyPayments,
                            'submit_authority', 'prepare_pack' => $canSubmitToAuthority,
                                    'open_handover' => $canReviewDocuments,
                            'open_quote' => $canBuildQuotes,
                            default => true,
                        };
                    @endphp
                    <div class="flex flex-col justify-between gap-3 rounded-md border border-line bg-white p-4 shadow-sm {{ $card['overdue'] ? 'ring-1 ring-red-300' : '' }}">
                        <div class="flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="font-mono text-2xl font-bold tracking-wide">
                                        {{ $card['vehicle_registration'] ?? '— no rego —' }}
                                    </div>
                                    @if ($card['vehicle_vin'])
                                        <div class="font-mono text-xs text-muted">VIN {{ $card['vehicle_vin'] }}</div>
                                    @endif
                                </div>
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $tone }}">
                                    {{ $card['stage_label'] }}
                                </span>
                            </div>

                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                                <span class="font-mono">{{ $card['application']->reference }}</span>
                                <span>&middot;</span>
                                <span>{{ $card['request_type_label'] }}</span>
                                <span>&middot;</span>
                                <span>Assigned to: {{ $card['reviewer']?->name ?? 'Unassigned' }}</span>
                            </div>

                            <div class="flex flex-wrap gap-1.5">
                                @if ($card['documents_required'] > 0)
                                    <span class="inline-flex items-center rounded-md bg-paper px-2 py-0.5 text-xs">
                                        Docs {{ $card['documents_accepted'] }}/{{ $card['documents_required'] }}
                                    </span>
                                @endif
                                @if ($card['documents_pending'] > 0)
                                    <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs text-amber-800">
                                        {{ $card['documents_pending'] }} to review
                                    </span>
                                @endif
                                @if ($card['documents_rejected'] > 0)
                                    <span class="inline-flex items-center rounded-md bg-red-50 px-2 py-0.5 text-xs text-red-700">
                                        {{ $card['documents_rejected'] }} rejected
                                    </span>
                                @endif
                                @if ($card['payment_on_statement'] ?? false)
                                    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs text-sky-700" title="Fee added to the dealership's monthly statement.">
                                        On statement
                                    </span>
                                @elseif ($card['payment_owed'])
                                    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs text-sky-700">
                                        Payment owed
                                    </span>
                                @elseif ($card['payment_awaiting_verification'])
                                    <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs text-amber-800">
                                        Payment to verify
                                    </span>
                                @elseif ($card['payment_verified'])
                                    <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                                        Payment verified
                                    </span>
                                @endif
                                @if ($card['is_ready_for_authority'])
                                    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs text-sky-700">
                                        Ready to submit
                                    </span>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <span class="text-muted">{{ $card['blocker'] ?? 'In progress' }}</span>
                                <span class="{{ $card['overdue'] ? 'font-semibold text-red-700' : 'text-muted' }}">
                                    @if ($card['due_at'])
                                        Due {{ $card['due_at']->format('d M H:i') }}
                                    @elseif ($card['waiting_since'])
                                        Since {{ $card['waiting_since']->diffForHumans() }}
                                    @endif
                                </span>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-2 border-t border-line pt-3">
                            <a href="{{ route('review.show', ['application' => $card['application']->id]) }}"
                                class="text-xs text-muted hover:text-ink">
                                Open application
                            </a>
                            @if ($actionAllowed && $card['next_action_key'] === 'submit_authority')
                                <button type="button" wire:click="openSubmitModal({{ $card['application']->id }})"
                                    class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium text-white"
                                    style="background: var(--brand);">
                                    Submit to authority
                                </button>
                            @elseif ($actionAllowed && $card['next_action_url'])
                                <a href="{{ $card['next_action_url'] }}"
                                    class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium text-white"
                                    style="background: var(--brand);">
                                    {{ $card['next_action_label'] }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- Submit-to-authority modal --}}
    @if ($submitApplicationId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            x-data
            @keydown.escape.window="$wire.cancelSubmitModal()">
            <div class="w-full max-w-md rounded-md border border-line bg-white p-5 shadow-lg">
                <h2 class="text-base font-semibold">Submit to authority</h2>
                <p class="mt-1 text-xs text-muted">Record the authority reference and the moment it was handed off. This transitions the application into "Submitted to authority".</p>

                <div class="mt-4 space-y-3">
                    <div>
                        <label for="authorityReference" class="block text-xs font-medium text-muted">Authority reference</label>
                        <input id="authorityReference" type="text" wire:model="authorityReference"
                            placeholder="e.g. GP-2026-00123"
                            class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                        @error('authorityReference') <p class="mt-1 text-xs text-red-800">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="authoritySubmittedAt" class="block text-xs font-medium text-muted">Submitted at</label>
                        <input id="authoritySubmittedAt" type="datetime-local" wire:model="authoritySubmittedAt"
                            max="{{ now()->format('Y-m-d\TH:i') }}"
                            class="mt-1 block h-9 w-full rounded-md border border-line bg-white px-2 text-sm">
                        @error('authoritySubmittedAt') <p class="mt-1 text-xs text-red-800">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" wire:click="cancelSubmitModal"
                        class="inline-flex items-center rounded-md border border-line bg-white px-3 py-1.5 text-sm hover:bg-paper">
                        Cancel
                    </button>
                    <button type="button" wire:click="confirmSubmitToAuthority"
                        class="inline-flex items-center rounded-md px-3 py-1.5 text-sm font-semibold text-white"
                        style="background: var(--brand);"
                        wire:loading.attr="disabled"
                        wire:target="confirmSubmitToAuthority">
                        <span wire:loading.remove wire:target="confirmSubmitToAuthority">Submit to authority</span>
                        <span wire:loading wire:target="confirmSubmitToAuthority">Submitting&hellip;</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
