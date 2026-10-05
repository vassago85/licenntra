<div>
<div class="mb-4 flex items-end justify-between gap-4">
    <div>
        <h1 class="text-xl font-semibold">Invoices</h1>
        <p class="text-sm text-muted">Invoices your licensing company has raised against applications on your account.</p>
    </div>
</div>

@if (session('status'))
    <p class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm">{{ session('status') }}</p>
@endif

<div class="mb-4 grid gap-3 sm:grid-cols-2">
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
</div>

<div class="mb-3 flex flex-wrap gap-2 text-xs">
    @foreach (['outstanding' => 'Outstanding', 'paid' => 'Paid', 'all' => 'All'] as $value => $label)
        <button
            type="button"
            wire:click="setFilter('{{ $value }}')"
            class="rounded-md border border-line px-3 py-1.5 {{ $filter === $value ? 'bg-white font-semibold' : 'text-muted' }}"
        >
            {{ $label }} · {{ $counts[$value] }}
        </button>
    @endforeach
</div>

<section class="rounded-md border border-line bg-white">
    <table class="w-full text-left text-sm">
        <thead class="text-xs text-muted">
            <tr class="border-b border-line">
                <th class="px-3 py-2 font-medium">Invoice</th>
                <th class="px-3 py-2 font-medium">Application</th>
                <th class="px-3 py-2 font-medium">Amount</th>
                <th class="px-3 py-2 font-medium">Status</th>
                <th class="px-3 py-2 font-medium">Uploaded</th>
                <th class="px-3 py-2 font-medium text-right"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $invoice)
                <tr class="border-b border-line last:border-0" wire:key="inv-{{ $invoice->id }}">
                    <td class="px-3 py-2 font-mono">{{ $invoice->invoice_number }}</td>
                    <td class="px-3 py-2">
                        <a href="{{ route('applications.show', $invoice->application) }}" class="font-mono text-xs underline">{{ $invoice->application?->reference }}</a>
                        @if ($invoice->application?->vehicle)
                            <span class="ml-1 text-xs text-muted">{{ $invoice->application->vehicle->make }} {{ $invoice->application->vehicle->model }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 font-mono">{{ $money::rands($invoice->amountCents()) }}</td>
                    <td class="px-3 py-2">
                        @if ($invoice->isPaid())
                            <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-900">Paid</span>
                            @if ($invoice->paid_at)
                                <span class="ml-1 text-xs text-muted">{{ $invoice->paid_at->format('d M Y') }}</span>
                            @endif
                        @else
                            <span class="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-900">Outstanding</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-muted">{{ $invoice->uploaded_at?->format('d M Y') }}</td>
                    <td class="px-3 py-2 text-right">
                        @can('download', $invoice)
                            <a class="h-8 rounded-md border border-line px-2 py-1.5 text-xs" href="{{ route('invoices.download', $invoice) }}">Download</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-muted">No invoices match this filter.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<div class="mt-3">{{ $rows->links() }}</div>
</div>
