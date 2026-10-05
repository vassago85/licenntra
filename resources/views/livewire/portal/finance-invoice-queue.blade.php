<div>
<div class="mb-4 flex items-end justify-between gap-4">
    <div>
        <h1 class="text-xl font-semibold">Invoices</h1>
        <p class="text-sm text-muted">Every invoice this deployment has raised. Mark paid once the money has landed.</p>
    </div>
</div>

@if (session('status'))
    <p class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm">{{ session('status') }}</p>
@endif

@if ($errors->any())
    <p class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm text-red-800">{{ $errors->first() }}</p>
@endif

<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div class="rounded-md border border-line bg-white p-3">
        <p class="text-xs uppercase tracking-wide text-muted">Outstanding</p>
        <p class="mt-1 text-xl font-semibold font-mono">{{ $money::rands($outstandingCents) }}</p>
        <p class="mt-0.5 text-xs text-muted">{{ $counts['outstanding'] }} invoice{{ $counts['outstanding'] === 1 ? '' : 's' }}</p>
    </div>
    <div class="rounded-md border border-line bg-white p-3">
        <p class="text-xs uppercase tracking-wide text-muted">Paid</p>
        <p class="mt-1 text-xl font-semibold font-mono">{{ $money::rands($paidCents) }}</p>
        <p class="mt-0.5 text-xs text-muted">{{ $counts['paid'] }} invoice{{ $counts['paid'] === 1 ? '' : 's' }}</p>
    </div>
    <div class="rounded-md border border-line bg-white p-3">
        <p class="text-xs uppercase tracking-wide text-muted">Dealerships with balance</p>
        <p class="mt-1 text-xl font-semibold">{{ $outstandingByAccount->count() }}</p>
        <p class="mt-0.5 text-xs text-muted">owing at least one invoice</p>
    </div>
</div>

<div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
    @foreach (['outstanding' => 'Outstanding', 'paid' => 'Paid', 'all' => 'All'] as $value => $label)
        <button
            type="button"
            wire:click="setFilter('{{ $value }}')"
            class="rounded-md border border-line px-3 py-1.5 {{ $filter === $value ? 'bg-white font-semibold' : 'text-muted' }}"
        >
            {{ $label }} · {{ $counts[$value] }}
        </button>
    @endforeach
    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search invoice no., ref or dealer" class="ml-auto h-9 w-64 rounded-md border border-line px-2 text-sm">
</div>

<section class="rounded-md border border-line bg-white">
    <table class="w-full text-left text-sm">
        <thead class="text-xs text-muted">
            <tr class="border-b border-line">
                <th class="px-3 py-2 font-medium">Invoice</th>
                <th class="px-3 py-2 font-medium">Dealership</th>
                <th class="px-3 py-2 font-medium">Application</th>
                <th class="px-3 py-2 font-medium">Amount</th>
                <th class="px-3 py-2 font-medium">Status</th>
                <th class="px-3 py-2 font-medium">Uploaded</th>
                <th class="px-3 py-2 font-medium text-right"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $invoice)
                <tr class="border-b border-line last:border-0" wire:key="finv-{{ $invoice->id }}">
                    <td class="px-3 py-2 font-mono">{{ $invoice->invoice_number }}</td>
                    <td class="px-3 py-2">{{ $invoice->application?->clientAccount?->name }}</td>
                    <td class="px-3 py-2">
                        <a href="{{ route('applications.show', $invoice->application) }}" class="font-mono text-xs underline">{{ $invoice->application?->reference }}</a>
                    </td>
                    <td class="px-3 py-2 font-mono">{{ $money::rands($invoice->amountCents()) }}</td>
                    <td class="px-3 py-2">
                        @if ($invoice->isPaid())
                            <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-900">Paid</span>
                            @if ($invoice->paid_reference)
                                <span class="ml-1 font-mono text-xs text-muted">{{ $invoice->paid_reference }}</span>
                            @endif
                        @else
                            <span class="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-900">Outstanding</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-muted">{{ $invoice->uploaded_at?->format('d M Y') }}</td>
                    <td class="px-3 py-2 text-right">
                        <div class="inline-flex items-center gap-1">
                            @can('download', $invoice)
                                <a class="h-8 rounded-md border border-line px-2 py-1.5 text-xs" href="{{ route('invoices.download', $invoice) }}">Download</a>
                            @endcan
                            @if ($invoice->isPaid())
                                @can('markUnpaid', $invoice)
                                    <button type="button" wire:click="markUnpaid({{ $invoice->id }})" wire:confirm="Mark this invoice as unpaid?" class="h-8 rounded-md border border-line px-2 py-1.5 text-xs">Mark unpaid</button>
                                @endcan
                            @else
                                @can('markPaid', $invoice)
                                    <button type="button" wire:click="startMarkPaid({{ $invoice->id }})" class="h-8 rounded-md px-2 py-1.5 text-xs font-semibold text-white" style="background: var(--brand)">Mark paid</button>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-muted">No invoices match this filter.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<div class="mt-3">{{ $rows->links() }}</div>

@if ($referencingInvoiceId)
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-black/40 p-4 sm:items-center">
        <form wire:submit="markPaid" class="w-full max-w-md rounded-md border border-line bg-white p-4 text-sm">
            <h2 class="text-base font-semibold">Mark invoice paid</h2>
            <p class="mt-1 text-xs text-muted">Record the reference your accounting system attached to the receipt. Optional, but useful for reconciliation.</p>
            <label class="mt-3 block">
                <span class="text-xs text-muted">Payment reference (optional)</span>
                <input type="text" wire:model="paidReference" maxlength="100" class="mt-1 h-9 w-full rounded-md border border-line px-2 text-sm font-mono" placeholder="e.g. EFT-2026-00412 or statement line #">
            </label>
            <div class="mt-4 flex items-center justify-end gap-2">
                <button type="button" wire:click="cancelMarkPaid" class="h-9 rounded-md border border-line px-3 text-sm">Cancel</button>
                <button type="submit" class="h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Mark paid</button>
            </div>
        </form>
    </div>
@endif

@if ($outstandingByAccount->isNotEmpty())
    <section class="mt-6 rounded-md border border-line bg-white">
        <h2 class="border-b border-line px-3 py-2 text-sm font-semibold">Outstanding by dealership</h2>
        <table class="w-full text-left text-sm">
            <thead class="text-xs text-muted">
                <tr class="border-b border-line">
                    <th class="px-3 py-2 font-medium">Dealership</th>
                    <th class="px-3 py-2 font-medium">Invoices</th>
                    <th class="px-3 py-2 font-medium text-right">Owing</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($outstandingByAccount as $name => $row)
                    <tr class="border-b border-line last:border-0">
                        <td class="px-3 py-2">{{ $name }}</td>
                        <td class="px-3 py-2">{{ $row['count'] }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $money::rands($row['cents']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif
</div>
