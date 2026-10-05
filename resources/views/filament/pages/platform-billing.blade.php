<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        {{-- Who is looking at this page --}}
        <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                Signed in as: {{ $viewerRole }}
            </span>
            @if ($canEditFee)
                <span>You can set the per-transaction fee.</span>
            @else
                <span>Read-only. Only the developer can change the fee.</span>
            @endif
        </div>

        {{-- Current-month hero tiles --}}
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Completed transactions · {{ $current['label'] }}
                </div>
                <div class="mt-1 font-mono text-3xl font-semibold text-gray-900 dark:text-gray-100">
                    {{ number_format($current['count']) }}
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Counts only applications that first entered the Completed stage this month. Cancelled work is excluded.
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Fee per transaction
                </div>
                <div class="mt-1 font-mono text-3xl font-semibold text-gray-900 dark:text-gray-100">
                    {{ $money::rands($current['fee_per_transaction_cents']) }}
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Set by the developer. Applies to transactions completed from the moment of the save.
                </div>
            </div>

            <div class="rounded-lg border border-primary-200 bg-primary-50 p-4 dark:border-primary-700 dark:bg-primary-900/20">
                <div class="text-xs uppercase tracking-wide text-primary-700 dark:text-primary-300">
                    Platform bill · {{ $current['label'] }}
                </div>
                <div class="mt-1 font-mono text-3xl font-semibold text-primary-900 dark:text-primary-100">
                    {{ $money::rands($current['total_cents']) }}
                </div>
                <div class="mt-1 text-xs text-primary-800 dark:text-primary-200">
                    Count × fee. Charsley Digital charges the owner this at month-end.
                </div>
            </div>
        </div>

        {{-- Fee editor (developer only) / read-only display (owner) --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">Per-completed-transaction fee</div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if ($canEditFee)
                            This is what Charsley Digital charges the licensing company owner for each application that reaches Completed. Changes are audited.
                        @else
                            The fee the developer has configured. Only the developer can change this value.
                        @endif
                    </p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-3">
                <label for="platformFeeRands" class="sr-only">Fee per transaction in rands</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-500 dark:text-gray-400">R</span>
                    <input
                        id="platformFeeRands"
                        type="number"
                        step="0.01"
                        min="0"
                        wire:model="platformFeeRands"
                        {{ $canEditFee ? '' : 'disabled' }}
                        class="block w-40 rounded-md border-gray-300 bg-white pl-7 pr-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-70 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    >
                </div>
                @if ($canEditFee)
                    <button
                        type="button"
                        wire:click="saveFee"
                        class="inline-flex items-center rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-primary-500"
                    >
                        Save fee
                    </button>
                @endif
            </div>
            @error('platformFeeRands')
                <div class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</div>
            @enderror
        </div>

        {{-- Recent months --}}
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Month</th>
                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Completed transactions</th>
                        <th scope="col" class="hidden lg:table-cell px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Fee / transaction</th>
                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                    @foreach ($recent as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                            <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{{ $row['label'] }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-mono text-sm text-gray-700 dark:text-gray-200">{{ number_format($row['count']) }}</td>
                            <td class="hidden lg:table-cell whitespace-nowrap px-3 py-2 text-right font-mono text-xs text-gray-500 dark:text-gray-400">{{ $money::rands($row['fee_per_transaction_cents']) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-mono text-sm text-gray-900 dark:text-gray-100">{{ $money::rands($row['total_cents']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
