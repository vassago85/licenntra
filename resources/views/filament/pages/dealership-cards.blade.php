<x-filament-panels::page>
    @php
        /** @var \Illuminate\Support\Collection $cards */
        /** @var \App\Models\ClientAccount $account */
        $toneClasses = [
            'warning' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30',
            'danger' => 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-500/10 dark:text-red-200 dark:ring-red-500/30',
            'success' => 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/30',
            'info' => 'bg-sky-50 text-sky-800 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-200 dark:ring-sky-500/30',
            'neutral' => 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700',
        ];
    @endphp

    <div class="flex flex-col gap-4">
        {{-- Dealership picker + filter bar --}}
        <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
                <div class="md:col-span-2">
                    <label for="dealership-picker" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Dealership</label>
                    <select
                        id="dealership-picker"
                        wire:model.live="account_id"
                        class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    >
                        @foreach ($allDealerships as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="card-search" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Registration or VIN</label>
                    <input
                        id="card-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="e.g. TLX123G or last 6 of VIN"
                        class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    >
                </div>
                <div>
                    <label for="card-stage" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Stage</label>
                    <select id="card-stage" wire:model.live="stage" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">Any stage</option>
                        @foreach ($stageOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" wire:model.live="overdue" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            Overdue
                        </label>
                        @if ($reviewerPicker)
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                <input type="checkbox" wire:model.live="mine" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                                Assigned to me
                            </label>
                        @endif
                    </div>
                    @include('filament.pages._partials.view-toggle', ['view' => $viewMode, 'wireModel' => 'viewMode'])
                </div>
            </div>
        </div>

        {{-- Summary strip --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">Active applications</div>
                <div class="font-mono text-xl text-gray-900 dark:text-gray-100">{{ $summary['total'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">Overdue</div>
                <div class="font-mono text-xl {{ $summary['overdue'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $summary['overdue'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">Payments owed</div>
                <div class="font-mono text-xl text-gray-900 dark:text-gray-100">{{ $summary['payment_owed'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">Payments to verify</div>
                <div class="font-mono text-xl text-gray-900 dark:text-gray-100">{{ $summary['payment_awaiting_verification'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">Ready for authority</div>
                <div class="font-mono text-xl text-gray-900 dark:text-gray-100">{{ $summary['ready_for_authority'] }}</div>
            </div>
        </div>

        @if ($cards->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                No active applications match these filters.
            </div>
        @elseif ($viewMode === 'table')
            {{-- Table view: dense list of the same cards --}}
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Registration / VIN</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Application</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Stage</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Docs</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Payment</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Reviewer</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Due</th>
                            <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                        @foreach ($cards as $card)
                            @php
                                $tone = $toneClasses[$card['stage_tone']] ?? $toneClasses['neutral'];
                                $actionAllowed = match ($card['next_action_key']) {
                                    'review_docs' => $canReviewDocuments,
                                    'verify_payment' => $canVerifyPayments,
                                    'submit_authority' => $canSubmitToAuthority,
                                    'open_quote' => $canBuildQuotes,
                                    default => true,
                                };
                            @endphp
                            <tr class="{{ $card['overdue'] ? 'bg-red-50/60 dark:bg-red-900/10' : '' }} hover:bg-gray-50 dark:hover:bg-gray-800">
                                <td class="px-3 py-2 text-sm">
                                    <div class="font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $card['vehicle_registration'] ?? '— no rego —' }}</div>
                                    @if ($card['vehicle_vin'])
                                        <div class="font-mono text-xs text-gray-500 dark:text-gray-400">VIN {{ \Illuminate\Support\Str::of($card['vehicle_vin'])->substr(-6) }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs">
                                    <div class="font-mono text-gray-700 dark:text-gray-200">{{ $card['application']->reference }}</div>
                                    <div class="text-gray-500 dark:text-gray-400">{{ $card['request_type_label'] }}</div>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 font-medium ring-1 ring-inset {{ $tone }}">{{ $card['stage_label'] }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs text-gray-700 dark:text-gray-200">
                                    @if ($card['documents_required'] > 0)
                                        {{ $card['documents_accepted'] }}/{{ $card['documents_required'] }}
                                        @if ($card['documents_rejected'] > 0)
                                            <span class="text-red-600 dark:text-red-400">· {{ $card['documents_rejected'] }} rejected</span>
                                        @endif
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs">
                                    @if ($card['payment_on_statement'] ?? false)
                                        <span class="text-sky-600 dark:text-sky-300">On statement</span>
                                    @elseif ($card['payment_owed'])
                                        <span class="text-sky-600 dark:text-sky-300">Owed</span>
                                    @elseif ($card['payment_awaiting_verification'])
                                        <span class="text-amber-600 dark:text-amber-300">To verify</span>
                                    @elseif ($card['payment_verified'])
                                        <span class="text-emerald-600 dark:text-emerald-300">Verified</span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs text-gray-700 dark:text-gray-200">{{ $card['reviewer']?->name ?? 'Unassigned' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs">
                                    @if ($card['due_at'])
                                        <span class="{{ $card['overdue'] ? 'font-semibold text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $card['due_at']->format('d M H:i') }}</span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right text-xs">
                                    @if (! $actionAllowed)
                                        <span class="text-gray-400">No access</span>
                                    @elseif ($card['next_action_key'] === 'submit_authority')
                                        {{ ($this->submitToAuthorityAction)(['application' => $card['application']->id]) }}
                                    @elseif ($card['next_action_url'])
                                        <a href="{{ $card['next_action_url'] }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
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
            {{-- Cards view --}}
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($cards as $card)
                    @php
                        $tone = $toneClasses[$card['stage_tone']] ?? $toneClasses['neutral'];
                        $actionAllowed = match ($card['next_action_key']) {
                            'review_docs' => $canReviewDocuments,
                            'verify_payment' => $canVerifyPayments,
                            'submit_authority' => $canSubmitToAuthority,
                            'open_quote' => $canBuildQuotes,
                            default => true,
                        };
                    @endphp

                    <div class="flex flex-col justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 {{ $card['overdue'] ? 'ring-1 ring-red-300 dark:ring-red-700' : '' }}">
                        <div class="flex flex-col gap-3">
                            {{-- Hero: registration + VIN --}}
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="font-mono text-2xl font-bold tracking-wide text-gray-900 dark:text-gray-100">
                                        {{ $card['vehicle_registration'] ?? '— no rego —' }}
                                    </div>
                                    @if ($card['vehicle_vin'])
                                        <div class="font-mono text-xs text-gray-500 dark:text-gray-400">VIN {{ $card['vehicle_vin'] }}</div>
                                    @endif
                                </div>
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $tone }}">
                                    {{ $card['stage_label'] }}
                                </span>
                            </div>

                            {{-- Meta line --}}
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                <span class="font-mono">{{ $card['application']->reference }}</span>
                                <span>&middot;</span>
                                <span>{{ $card['request_type_label'] }}</span>
                                <span>&middot;</span>
                                <span>Reviewer: {{ $card['reviewer']?->name ?? 'Unassigned' }}</span>
                            </div>

                            {{-- Progress chips --}}
                            <div class="flex flex-wrap gap-1.5">
                                @if ($card['documents_required'] > 0)
                                    <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                        Docs {{ $card['documents_accepted'] }}/{{ $card['documents_required'] }}
                                    </span>
                                @endif
                                @if ($card['documents_pending'] > 0)
                                    <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                                        {{ $card['documents_pending'] }} to review
                                    </span>
                                @endif
                                @if ($card['documents_rejected'] > 0)
                                    <span class="inline-flex items-center rounded-md bg-red-50 px-2 py-0.5 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-200">
                                        {{ $card['documents_rejected'] }} rejected
                                    </span>
                                @endif
                                @if ($card['payment_on_statement'] ?? false)
                                    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs text-sky-700 dark:bg-sky-500/10 dark:text-sky-200" title="Fee added to the dealership's monthly statement. Documents move on without waiting for cash.">
                                        On statement
                                    </span>
                                @elseif ($card['payment_owed'])
                                    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs text-sky-700 dark:bg-sky-500/10 dark:text-sky-200">
                                        Payment owed
                                    </span>
                                @elseif ($card['payment_awaiting_verification'])
                                    <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                                        Payment to verify
                                    </span>
                                @elseif ($card['payment_verified'])
                                    <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">
                                        Payment verified
                                    </span>
                                @endif
                                @if ($card['is_ready_for_authority'])
                                    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs text-sky-700 dark:bg-sky-500/10 dark:text-sky-200">
                                        Ready to submit
                                    </span>
                                @endif
                            </div>

                            {{-- Blocker / due --}}
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <span class="text-gray-500 dark:text-gray-400">
                                    {{ $card['blocker'] ?? 'In progress' }}
                                </span>
                                <span class="{{ $card['overdue'] ? 'font-semibold text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">
                                    @if ($card['due_at'])
                                        Due {{ $card['due_at']->format('d M H:i') }}
                                    @elseif ($card['waiting_since'])
                                        Since {{ $card['waiting_since']->diffForHumans() }}
                                    @endif
                                </span>
                            </div>
                        </div>

                        {{-- Action footer --}}
                        <div class="flex items-center justify-between gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                            <a
                                href="{{ route('review.show', ['application' => $card['application']->id]) }}"
                                class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                            >
                                Open application
                            </a>
                            @if (! $actionAllowed)
                                <span class="inline-flex items-center rounded-md border border-dashed border-gray-200 px-2.5 py-1 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500" title="Not permitted for your role">
                                    No access
                                </span>
                            @elseif ($card['next_action_key'] === 'submit_authority')
                                {{ ($this->submitToAuthorityAction)(['application' => $card['application']->id]) }}
                            @elseif ($card['next_action_url'])
                                <a
                                    href="{{ $card['next_action_url'] }}"
                                    class="inline-flex items-center rounded-md bg-primary-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-500"
                                >
                                    {{ $card['next_action_label'] }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
