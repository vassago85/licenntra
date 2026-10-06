@php
    $counterTones = [
        'danger' => 'text-red-800',
        'warning' => 'text-amber-800',
        'info' => 'text-blue-800',
        'neutral' => 'text-ink',
    ];
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Overview</h1>
        <p class="text-sm text-muted">What needs doing across every dealership{{ $seesMoney ? ', and where the money stands' : '' }}.</p>
    </div>

    <section aria-label="Operations">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($counters as $counter)
                <a href="{{ $counter['url'] }}" class="rounded-md border border-line bg-white p-3 hover:border-ink">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ $counter['label'] }}</p>
                    <p class="mt-1 text-xl font-semibold {{ $counter['count'] > 0 ? ($counterTones[$counter['tone']] ?? 'text-ink') : 'text-ink' }}">{{ $counter['count'] }}</p>
                    <p class="mt-0.5 text-xs text-muted">{{ $counter['description'] }}</p>
                </a>
            @endforeach
        </div>
    </section>

    <section aria-label="Worklist" class="overflow-hidden rounded-md border border-line bg-white">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-3 py-2">
            <h2 class="text-sm font-semibold">
                Worklist
                <span class="ml-1 font-normal text-muted">{{ $worklistTotal }} {{ \Illuminate\Support\Str::plural('task', $worklistTotal) }}@if ($worklistOverdue > 0) · <span class="font-semibold text-red-800">{{ $worklistOverdue }} past warning time</span>@endif</span>
            </h2>
            <div class="flex items-center gap-3 text-xs">
                <div role="group" aria-label="Whose tasks" class="inline-flex overflow-hidden rounded-md border border-line">
                    <button type="button" wire:click="$set('mineOnly', false)" aria-pressed="{{ $mineOnly ? 'false' : 'true' }}" class="px-2 py-1 {{ $mineOnly ? 'bg-white text-muted hover:text-ink' : 'bg-ink text-white' }}">Everyone</button>
                    <button type="button" wire:click="$set('mineOnly', true)" aria-pressed="{{ $mineOnly ? 'true' : 'false' }}" class="border-l border-line px-2 py-1 {{ $mineOnly ? 'bg-ink text-white' : 'bg-white text-muted hover:text-ink' }}">Assigned to me</button>
                </div>
                <a href="{{ $workload->tabUrl(\App\Services\OperationsWorkloadService::TAB_OUTSTANDING) }}" class="text-muted hover:underline">Outstanding tasks →</a>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Waiting</th>
                        <th class="px-3 py-2 font-medium">Customer</th>
                        <th class="px-3 py-2 font-medium">Vehicle</th>
                        <th class="px-3 py-2 font-medium">Required action</th>
                        <th class="px-3 py-2 font-medium">Assigned to</th>
                        <th class="px-3 py-2"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($worklist as $task)
                        @php
                            $allowed = match ($task['kind']) {
                                'approval.document' => $canReviewDocuments,
                                'approval.payment' => $canVerifyPayments,
                                default => $canHandleCases,
                            };
                            $days = $task['days_waiting'];
                        @endphp
                        <tr wire:key="work-{{ $task['task_key'] }}" class="{{ $task['urgency'] === \App\Services\OperationsWorkloadService::URGENCY_OVERDUE ? 'bg-red-50/60' : '' }}">
                            <td class="whitespace-nowrap px-3 py-2 align-top">
                                <div class="tabular-nums">{{ $days === null ? '—' : ($days === 0 ? 'Today' : $days.' '.\Illuminate\Support\Str::plural('day', $days)) }}</div>
                                @if ($task['urgency'] === \App\Services\OperationsWorkloadService::URGENCY_OVERDUE)
                                    <span class="text-xs font-semibold text-red-800" title="Due {{ $task['due_at']?->format('d M H:i') }}">Past warning time</span>
                                @elseif ($task['urgency'] === \App\Services\OperationsWorkloadService::URGENCY_DUE_SOON)
                                    <span class="text-xs font-semibold text-amber-800">Due {{ $task['due_at']->isToday() ? 'today' : 'tomorrow' }} {{ $task['due_at']->format('H:i') }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 align-top">
                                <div class="font-medium">{{ $task['account']?->name ?? '—' }}</div>
                                @if ($task['submitted_by'])
                                    <div class="text-xs text-muted">{{ $task['submitted_by']->name }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 align-top text-xs">
                                @if ($task['vehicle_registration'])
                                    <div class="font-mono">{{ $task['vehicle_registration'] }}</div>
                                @elseif ($task['vehicle_vin'])
                                    <div class="font-mono">VIN …{{ \Illuminate\Support\Str::of($task['vehicle_vin'])->substr(-6) }}</div>
                                @endif
                                <div class="font-mono text-muted">{{ $task['application']?->reference ?? '—' }}</div>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <div class="font-medium">{{ $task['label'] }}</div>
                                @if ($task['blocker'])
                                    <div class="text-xs text-muted">{{ $task['blocker'] }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 align-top text-xs">
                                @if ($task['reviewer'])
                                    {{ $task['reviewer']->name }}
                                @else
                                    <span class="text-amber-800">Unassigned</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right align-top">
                                @if ($allowed)
                                    <a href="{{ $task['action_url'] }}" class="inline-flex items-center rounded-md border border-line bg-white px-2.5 py-1 text-xs font-medium hover:bg-paper">{{ $task['action_label'] }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-muted">{{ $mineOnly ? 'Nothing assigned to you needs doing right now.' : 'Nothing needs doing right now.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($worklistTotal > $worklist->count())
            <div class="border-t border-line px-3 py-2 text-center">
                <button type="button" wire:click="showMoreWork" class="text-xs font-medium text-muted hover:text-ink hover:underline">
                    Show more ({{ $worklistTotal - $worklist->count() }} more)
                </button>
            </div>
        @endif
    </section>

    <section aria-label="Dealerships needing action" class="overflow-hidden rounded-md border border-line bg-white">
        <div class="flex items-center justify-between border-b border-line px-3 py-2">
            <h2 class="text-sm font-semibold">Dealerships needing action</h2>
            <a href="{{ route('dealerships.board') }}" class="text-xs text-muted hover:underline">Dealership board →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Dealership</th>
                        <th class="px-3 py-2 text-right font-medium">Docs to approve</th>
                        <th class="px-3 py-2 text-right font-medium">Owed by dealer</th>
                        <th class="px-3 py-2 text-right font-medium">Ready to submit</th>
                        <th class="px-3 py-2 text-right font-medium">Overdue</th>
                        <th class="px-3 py-2 font-medium">Oldest task</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($dealerships as $row)
                        @php
                            $owedByDealer = $row['waiting_on_dealership_docs'] + ($quotesEnabled ? $row['quotes_awaiting_dealership'] : 0) + $row['payments_owed_by_dealership'];
                            $oldestDays = $row['oldest_outstanding_at']?->diffInDays(now());
                        @endphp
                        <tr wire:key="dealership-{{ $row['id'] }}">
                            <td class="px-3 py-2">
                                <a href="{{ $workload->cardsUrl($row['account']) }}" class="font-medium hover:underline">{{ $row['name'] }}</a>
                                <div class="text-xs text-muted">{{ $row['type']?->label() }} · {{ $row['active_applications'] }} active</div>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <a href="{{ $workload->tabUrl(\App\Services\OperationsWorkloadService::TAB_APPROVALS, ['account_id' => $row['id'], 'kind' => \App\Services\OperationsWorkloadService::KIND_DOCUMENT]) }}" class="{{ $row['awaiting_document_approval'] > 0 ? 'font-semibold text-amber-800' : 'text-muted' }} hover:underline">{{ $row['awaiting_document_approval'] }}</a>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <a href="{{ $workload->tabUrl(\App\Services\OperationsWorkloadService::TAB_WAITING_ON_DEALERSHIP, ['account_id' => $row['id']]) }}" class="{{ $owedByDealer > 0 ? 'text-blue-800' : 'text-muted' }} hover:underline">{{ $owedByDealer }}</a>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <a href="{{ $workload->tabUrl(\App\Services\OperationsWorkloadService::TAB_READY_TO_SUBMIT, ['account_id' => $row['id']]) }}" class="{{ $row['ready_for_authority_submission'] > 0 ? 'text-blue-800' : 'text-muted' }} hover:underline">{{ $row['ready_for_authority_submission'] }}</a>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <a href="{{ $workload->tabUrl(\App\Services\OperationsWorkloadService::TAB_ALL, ['account_id' => $row['id'], 'overdue' => 1]) }}" class="{{ $row['overdue'] > 0 ? 'font-semibold text-red-800' : 'text-muted' }} hover:underline">{{ $row['overdue'] }}</a>
                            </td>
                            <td class="px-3 py-2 text-xs {{ $oldestDays === null ? 'text-muted' : ($oldestDays > 10 ? 'text-red-800' : ($oldestDays > 5 ? 'text-amber-800' : '')) }}">
                                {{ $row['oldest_outstanding_at']?->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-muted">No dealership needs action right now.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($seesMoney && $summary)
        @php
            $delta = $summary['received_this_month_cents'] - $summary['received_last_month_cents'];
        @endphp
        <section aria-label="Money">
            <h2 class="mb-2 text-sm font-semibold">Money</h2>
            <div class="grid gap-3 sm:grid-cols-2 {{ $quotesEnabled ? 'lg:grid-cols-4' : 'lg:grid-cols-3' }}">
                <a href="{{ route('finance.invoices') }}" class="rounded-md border border-line bg-white p-3 hover:border-ink">
                    <p class="text-xs uppercase tracking-wide text-muted">Outstanding</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums {{ $summary['outstanding_cents'] > 0 ? 'text-amber-800' : '' }}">{{ \App\Support\Money::rands($summary['outstanding_cents']) }}</p>
                    <p class="mt-0.5 text-xs text-muted">
                        @if ($summary['oldest_days'] === null)
                            Nothing owed right now
                        @else
                            Oldest {{ $summary['oldest_days'] }} days · {{ $summary['account_count'] }} {{ \Illuminate\Support\Str::plural('account', $summary['account_count']) }}
                        @endif
                    </p>
                </a>
                <div class="rounded-md border border-line bg-white p-3">
                    <p class="text-xs uppercase tracking-wide text-muted">Received this month</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">{{ \App\Support\Money::rands($summary['received_this_month_cents']) }}</p>
                    <p class="mt-0.5 text-xs text-muted">{{ $delta >= 0 ? '+' : '−' }}{{ \App\Support\Money::rands(abs($delta)) }} vs last month</p>
                </div>
                @if ($quotesEnabled)
                    <div class="rounded-md border border-line bg-white p-3">
                        <p class="text-xs uppercase tracking-wide text-muted">Open quotes</p>
                        <p class="mt-1 text-xl font-semibold">{{ $summary['open_quote_count'] }}</p>
                        <p class="mt-0.5 text-xs text-muted">{{ $summary['open_quote_count'] > 0 ? \App\Support\Money::rands($summary['open_quote_cents']).' if accepted' : 'None awaiting a decision' }}</p>
                    </div>
                @endif
                <div class="rounded-md border border-line bg-white p-3">
                    <p class="text-xs uppercase tracking-wide text-muted">Last payment</p>
                    <p class="mt-1 text-xl font-semibold">{{ $summary['last_payment_at']?->diffForHumans() ?? 'None yet' }}</p>
                    <p class="mt-0.5 text-xs text-muted">
                        @if ($summary['last_payment'])
                            {{ \App\Support\Money::rands($summary['last_payment']->amount_cents) }} · {{ $summary['last_payment']->application?->reference ?? 'App #'.$summary['last_payment']->application_id }}
                        @endif
                    </p>
                </div>
            </div>
        </section>
    @endif

    @if ($seesMoney)
        <div class="grid gap-6 xl:grid-cols-2">
            <section aria-label="Customers owing" class="overflow-hidden rounded-md border border-line bg-white">
                <h2 class="border-b border-line px-3 py-2 text-sm font-semibold">Customers — outstanding payments</h2>
                <table class="w-full text-left text-sm">
                    <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th class="px-3 py-2 font-medium">Account</th>
                            <th class="px-3 py-2 text-right font-medium">Outstanding</th>
                            <th class="px-3 py-2 font-medium">Oldest</th>
                            <th class="px-3 py-2 text-right font-medium">Received 90d</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($customerBalances as $row)
                            <tr wire:key="balance-{{ $row['account']->id }}">
                                <td class="px-3 py-2">
                                    <div class="font-medium">{{ $row['account']->name }}</div>
                                    @if ($quotesEnabled)
                                        <div class="text-xs text-muted">{{ $row['open_quotes'] }} open {{ \Illuminate\Support\Str::plural('quote', $row['open_quotes']) }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums {{ $row['outstanding_cents'] > 0 ? 'font-medium text-amber-800' : 'text-muted' }}">{{ \App\Support\Money::rands($row['outstanding_cents']) }}</td>
                                <td class="px-3 py-2 text-xs {{ ($row['oldest_days'] ?? 0) > 60 ? 'text-red-800' : (($row['oldest_days'] ?? 0) > 30 ? 'text-amber-800' : 'text-muted') }}">{{ $row['oldest_days'] !== null ? $row['oldest_days'].' days' : '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-emerald-800">{{ \App\Support\Money::rands($row['received_90d_cents']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-8 text-center text-muted">Nothing owed and nothing received in the last 90 days.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            <section aria-label="Top customers" class="overflow-hidden rounded-md border border-line bg-white">
                <h2 class="border-b border-line px-3 py-2 text-sm font-semibold">Top customers — last 90 days</h2>
                <table class="w-full text-left text-sm">
                    <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th class="px-3 py-2 font-medium">#</th>
                            <th class="px-3 py-2 font-medium">Account</th>
                            <th class="px-3 py-2 text-right font-medium">Apps</th>
                            <th class="px-3 py-2 text-right font-medium">Revenue</th>
                            <th class="px-3 py-2 text-right font-medium">Avg</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($topCustomers as $index => $row)
                            <tr wire:key="top-{{ $row['account']->id }}">
                                <td class="px-3 py-2 font-mono text-xs text-muted">{{ $index + 1 }}</td>
                                <td class="px-3 py-2 font-medium">{{ $row['account']->name }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $row['applications'] }}</td>
                                <td class="px-3 py-2 text-right font-medium tabular-nums">{{ \App\Support\Money::rands($row['revenue_cents']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-muted">{{ $row['average_cents'] !== null ? \App\Support\Money::rands($row['average_cents']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-8 text-center text-muted">No money received in the last 90 days.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>

        <section aria-label="Transactions" class="overflow-hidden rounded-md border border-line bg-white">
            <div class="flex items-center justify-between border-b border-line px-3 py-2">
                <h2 class="text-sm font-semibold">Transactions</h2>
                @if (\App\Services\FeatureFlags::paymentTrackingRequired())
                    <a href="{{ route('finance.payments') }}" class="text-xs text-muted hover:underline">Payments →</a>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th class="px-3 py-2 font-medium">Captured</th>
                            <th class="px-3 py-2 font-medium">Account</th>
                            <th class="px-3 py-2 font-medium">Application</th>
                            <th class="px-3 py-2 font-medium">Method / reference</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($transactions as $payment)
                            <tr wire:key="payment-{{ $payment->id }}">
                                <td class="px-3 py-2 text-xs">{{ $payment->created_at?->format('d M H:i') }}</td>
                                <td class="px-3 py-2 text-xs">{{ $payment->application?->clientAccount?->name ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    @if ($payment->application)
                                        <a href="{{ route('review.show', $payment->application) }}" class="hover:underline">{{ $payment->application->reference }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs">{{ $payment->method ?? '—' }} <span class="font-mono text-muted">{{ $payment->reference }}</span></td>
                                <td class="px-3 py-2 text-xs">
                                    @if ($payment->on_account && $payment->statement_settled_at)
                                        <span class="text-emerald-700">Invoice paid</span>
                                        <span class="text-muted">{{ $payment->statement_settled_at->format('d M') }}</span>
                                    @elseif ($payment->on_account)
                                        <span class="text-amber-800">Billed, unpaid</span>
                                    @elseif ($payment->verified_at)
                                        <span class="text-emerald-700">Verified</span>
                                        <span class="text-muted">by {{ $payment->verifier?->name ?? '—' }}</span>
                                    @else
                                        <span class="text-amber-800">Awaiting verification</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-medium tabular-nums">{{ \App\Support\Money::rands($payment->amount_cents) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-muted">No transactions yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section aria-label="Recent activity" class="overflow-hidden rounded-md border border-line bg-white">
        <div class="flex items-center justify-between border-b border-line px-3 py-2">
            <h2 class="text-sm font-semibold">Recent activity</h2>
            @if (auth()->user()->hasRole('owner'))
                <a href="{{ route('audit.index') }}" class="text-xs text-muted hover:underline">Audit log →</a>
            @endif
        </div>
        <ul class="divide-y divide-line text-sm">
            @forelse ($activity as $event)
                <li wire:key="event-{{ $event->id }}" class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-3 py-2">
                    <span class="w-24 shrink-0 text-xs text-muted">{{ $event->occurred_at?->format('d M H:i') }}</span>
                    <span class="font-medium">{{ $event->actor?->name ?? 'System' }}</span>
                    <span class="grow">{{ $event->summary }}</span>
                    <span class="font-mono text-xs text-muted">{{ class_basename((string) $event->subject_type) }} #{{ $event->subject_id }}</span>
                </li>
            @empty
                <li class="px-3 py-8 text-center text-muted">No activity yet.</li>
            @endforelse
        </ul>
    </section>
</div>
