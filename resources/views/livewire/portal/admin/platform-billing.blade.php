<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Platform billing</h1>
            <p class="text-sm text-muted">Monthly completed-transaction counter and the resulting bill between Charsley Digital and the licensing company owner.</p>
        </div>
        <span class="inline-flex items-center rounded-full bg-paper px-2 py-0.5 text-xs font-medium text-ink ring-1 ring-inset ring-line">
            Signed in as: {{ $viewerRole }}
        </span>
    </div>

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif

    <p class="mb-4 text-xs text-muted">
        @if ($canEditFee)
            You can set the per-transaction fee.
        @else
            Read-only. Only the developer can change the fee.
        @endif
    </p>

    {{-- Current-month hero tiles --}}
    <div class="mb-6 grid grid-cols-1 gap-3 md:grid-cols-3">
        <div class="rounded-md border border-line bg-white p-4">
            <div class="text-xs uppercase tracking-wide text-muted">Completed transactions &middot; {{ $current['label'] }}</div>
            <div class="mt-1 font-mono text-3xl font-semibold">{{ number_format($current['count']) }}</div>
            <div class="mt-1 text-xs text-muted">Counts only applications that first entered the Completed stage this month. Cancelled work is excluded.</div>
        </div>

        <div class="rounded-md border border-line bg-white p-4">
            <div class="text-xs uppercase tracking-wide text-muted">Fee per transaction</div>
            <div class="mt-1 font-mono text-3xl font-semibold">{{ $money::rands($current['fee_per_transaction_cents']) }}</div>
            <div class="mt-1 text-xs text-muted">Set by the developer. Applies to transactions completed from the moment of the save.</div>
        </div>

        <div class="rounded-md border p-4" style="border-color: var(--brand); background: var(--brand-soft);">
            <div class="text-xs uppercase tracking-wide" style="color: var(--brand);">Platform bill &middot; {{ $current['label'] }}</div>
            <div class="mt-1 font-mono text-3xl font-semibold" style="color: var(--brand);">{{ $money::rands($current['total_cents']) }}</div>
            <div class="mt-1 text-xs" style="color: var(--brand);">Count &times; fee. Charsley Digital charges the owner this at month-end.</div>
        </div>
    </div>

    {{-- Fee editor --}}
    <section class="mb-6 rounded-md border border-line bg-white p-4">
        <div>
            <h2 class="text-sm font-semibold">Per-completed-transaction fee</h2>
            <p class="mt-1 text-xs text-muted">
                @if ($canEditFee)
                    This is what Charsley Digital charges the licensing company owner for each application that reaches Completed. Changes are audited.
                @else
                    The fee the developer has configured. Only the developer can change this value.
                @endif
            </p>
        </div>

        <div class="mt-4 flex flex-wrap items-end gap-3">
            <label for="platformFeeRands" class="sr-only">Fee per transaction in rands</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-muted">R</span>
                <input
                    id="platformFeeRands"
                    type="number"
                    step="0.01"
                    min="0"
                    wire:model="platformFeeRands"
                    {{ $canEditFee ? '' : 'disabled' }}
                    class="h-10 w-40 rounded-md border border-line bg-white pl-7 pr-2 text-sm disabled:cursor-not-allowed disabled:bg-paper disabled:opacity-70">
            </div>
            @if ($canEditFee)
                <button type="button" wire:click="saveFee"
                    class="h-10 rounded-md px-4 text-sm font-semibold text-white"
                    style="background: var(--brand);"
                    wire:loading.attr="disabled"
                    wire:target="saveFee">
                    <span wire:loading.remove wire:target="saveFee">Save fee</span>
                    <span wire:loading wire:target="saveFee">Saving&hellip;</span>
                </button>
            @endif
        </div>
        @error('platformFeeRands') <p class="mt-2 text-xs text-red-800">{{ $message }}</p> @enderror
    </section>

    {{-- Recent months --}}
    <section class="overflow-hidden rounded-md border border-line bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                <tr>
                    <th class="px-3 py-2 font-medium">Month</th>
                    <th class="px-3 py-2 text-right font-medium">Completed transactions</th>
                    <th class="hidden px-3 py-2 text-right font-medium lg:table-cell">Fee / transaction</th>
                    <th class="px-3 py-2 text-right font-medium">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($recent as $row)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-2">{{ $row['label'] }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ number_format($row['count']) }}</td>
                        <td class="hidden whitespace-nowrap px-3 py-2 text-right font-mono text-xs text-muted lg:table-cell">{{ $money::rands($row['fee_per_transaction_cents']) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ $money::rands($row['total_cents']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
