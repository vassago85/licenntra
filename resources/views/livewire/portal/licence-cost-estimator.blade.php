<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3 print:hidden">
        <div>
            <h1 class="text-xl font-semibold">Licence cost estimate</h1>
            <p class="text-sm text-muted">For costing purposes only &mdash; always confirm the amount with the licensing company before billing a client.</p>
        </div>
    </div>

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 print:hidden">{{ $statusMessage }}</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-5">
        {{-- Inputs --}}
        <section class="rounded-md border border-line bg-white p-4 lg:col-span-2 print:hidden">
            <h2 class="mb-3 text-sm font-semibold">Inputs</h2>

            <form wire:submit.prevent="calculate" class="grid gap-3">
                <label class="block text-sm">
                    <span class="text-muted">Province / licensing department</span>
                    <select wire:model="province" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @foreach ($provinces as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('province') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Vehicle category</span>
                    <select wire:model="licence_category" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @foreach ($categories as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <span class="mt-1 block text-xs text-muted">Tare and province alone can be ambiguous &mdash; the category is required.</span>
                    @error('licence_category') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Tare weight (kg)</span>
                    <input wire:model="tare_kg" type="number" min="0" max="60000" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('tare_kg') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Applicable date</span>
                    <input wire:model="applicable_date" type="date" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm" required>
                    @error('applicable_date') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <div class="flex flex-wrap gap-2 pt-1">
                    <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Calculate</button>
                    @if ($result !== null)
                        <button type="button" wire:click="save" class="h-10 rounded-md border border-line bg-white px-4 text-sm font-semibold">
                            Save estimate
                        </button>
                        <button type="button" onclick="window.print()" class="h-10 rounded-md border border-line bg-white px-4 text-sm font-semibold">
                            Print
                        </button>
                    @endif
                </div>
            </form>
        </section>

        {{-- Result --}}
        <section class="rounded-md border border-line bg-white p-4 lg:col-span-3">
            @if ($result === null)
                <div class="flex h-full min-h-[12rem] items-center justify-center rounded-md border border-dashed border-line p-6 text-center text-sm text-muted">
                    Choose inputs on the left and press <span class="mx-1 font-semibold">Calculate</span> to see an estimate.
                </div>
            @else
                @php
                    $isConfirmationRequired = $result['status'] === \App\Models\LicenceEstimate::STATUS_CONFIRMATION_REQUIRED;
                    $moneyRands = fn (int $cents): string => 'R '.number_format($cents / 100, 2, '.', ' ');
                @endphp

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">Estimate for costing purposes</h2>
                        <p class="mt-0.5 text-xs text-muted">
                            Calculated {{ $result['computed_at']->format('d M Y H:i') }}
                            @if ($result['fee_table_version_number'])
                                &middot; schedule version {{ $result['fee_table_version_number'] }}
                                @if ($result['fee_table_effective_from'])
                                    (effective from {{ $result['fee_table_effective_from']->format('d M Y') }}@if ($result['fee_table_effective_until']) &ndash; {{ $result['fee_table_effective_until']->format('d M Y') }}@endif)
                                @endif
                            @else
                                &middot; <span class="font-medium text-amber-900">no approved schedule in force</span>
                            @endif
                        </p>
                    </div>
                    @if ($editingEstimateId)
                        <span class="rounded-full border border-line bg-paper px-2 py-0.5 text-xs text-muted">Saved estimate #{{ $editingEstimateId }}</span>
                    @endif
                </div>

                @if ($isConfirmationRequired)
                    <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <p class="font-semibold">Fee confirmation required</p>
                        <p class="mt-1">{{ $result['confirmation_reason'] }}</p>
                        <p class="mt-1 text-xs">A total cannot be shown until the licensing company confirms the applicable rate &mdash; no fallback or expired rate is used.</p>
                    </div>
                @endif

                <dl class="mt-4 grid gap-y-2 text-sm sm:grid-cols-2">
                    <dt class="text-muted">Province</dt>
                    <dd class="font-medium">{{ $result['province']?->label() }}</dd>

                    <dt class="text-muted">Vehicle category</dt>
                    <dd class="font-medium">{{ $result['licence_category']?->label() }}</dd>

                    <dt class="text-muted">Tare weight</dt>
                    <dd class="font-medium">
                        {{ $result['tare_kg'] !== null ? number_format($result['tare_kg']).' kg' : 'Not provided' }}
                    </dd>

                    <dt class="text-muted">Applicable date</dt>
                    <dd class="font-medium">{{ $result['applicable_date']->format('d M Y') }}</dd>

                    @if ($result['fee_line_label'])
                        <dt class="text-muted">Matched band</dt>
                        <dd class="font-medium">
                            {{ $result['fee_line_label'] }}
                            @if ($result['fee_line_tare_min_kg'] !== null || $result['fee_line_tare_max_kg'] !== null)
                                <span class="text-xs text-muted">
                                    ({{ $result['fee_line_tare_min_kg'] ?? '0' }}&ndash;{{ $result['fee_line_tare_max_kg'] ?? '∞' }} kg)
                                </span>
                            @endif
                        </dd>
                    @endif
                </dl>

                <div class="mt-4 overflow-hidden rounded-md border border-line">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">Charge</th>
                                <th class="px-3 py-2 text-left font-medium">Tax treatment</th>
                                <th class="px-3 py-2 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr>
                                <td class="px-3 py-2">Estimated provincial licence fee</td>
                                <td class="px-3 py-2 text-xs text-muted">{{ ucfirst($result['licence_fee_tax_treatment']->value) }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if ($isConfirmationRequired)
                                        <span class="text-amber-900">Confirmation required</span>
                                    @else
                                        {{ $moneyRands($result['licence_fee_cents']) }}
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2">Estimated admin charge</td>
                                <td class="px-3 py-2 text-xs text-muted">{{ ucfirst($result['admin_charge_tax_treatment']->value) }}</td>
                                <td class="px-3 py-2 text-right">{{ $moneyRands($result['admin_charge_cents']) }}</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2">
                                    RTMC transaction fee
                                    <span class="block text-xs text-muted">National R72 pass-through on every licence transaction</span>
                                </td>
                                <td class="px-3 py-2 text-xs text-muted">{{ ucfirst(($result['rtmc_transaction_fee_tax_treatment'] ?? \App\Enums\TaxTreatment::Exempt)->value) }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if ($isConfirmationRequired)
                                        <span class="text-amber-900">&mdash;</span>
                                    @else
                                        {{ $moneyRands($result['rtmc_transaction_fee_cents'] ?? 0) }}
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2">VAT ({{ number_format($result['vat_basis_points'] / 100, 1) }}%)</td>
                                <td class="px-3 py-2 text-xs text-muted">On taxable items</td>
                                <td class="px-3 py-2 text-right">
                                    @if ($isConfirmationRequired)
                                        <span class="text-amber-900">&mdash;</span>
                                    @else
                                        {{ $moneyRands($result['vat_cents']) }}
                                    @endif
                                </td>
                            </tr>
                            <tr class="bg-paper font-semibold">
                                <td class="px-3 py-2">Total estimate</td>
                                <td class="px-3 py-2"></td>
                                <td class="px-3 py-2 text-right">
                                    @if ($isConfirmationRequired)
                                        <span class="text-amber-900">Confirmation required</span>
                                    @else
                                        {{ $moneyRands($result['total_cents']) }}
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2 print:hidden">
                    <label class="block text-sm">
                        <span class="text-muted">Attach to draft application (optional)</span>
                        <select wire:model="application_id" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            <option value="">&mdash; none &mdash;</option>
                            @foreach ($draftApplications as $app)
                                <option value="{{ $app->id }}">{{ $app->reference }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Notes (optional, saved with estimate)</span>
                        <input wire:model="notes" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    </label>
                </div>
            @endif
        </section>
    </div>

    {{-- Saved estimates --}}
    <section class="mt-6 rounded-md border border-line bg-white print:hidden">
        <div class="flex items-center justify-between border-b border-line px-4 py-3">
            <h2 class="text-sm font-semibold">Saved estimates for your account</h2>
            <span class="text-xs text-muted">{{ $saved->count() }} most recent</span>
        </div>
        @if ($saved->isEmpty())
            <div class="p-4 text-sm text-muted">No saved estimates yet. Press <span class="font-semibold">Save estimate</span> after calculating.</div>
        @else
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Computed</th>
                        <th class="px-3 py-2 font-medium">Province</th>
                        <th class="px-3 py-2 font-medium">Category</th>
                        <th class="px-3 py-2 font-medium">Tare</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Total</th>
                        <th class="px-3 py-2 font-medium">Application</th>
                        <th class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($saved as $estimate)
                        <tr>
                            <td class="px-3 py-2 text-xs text-muted">{{ $estimate->computed_at->format('d M Y H:i') }}</td>
                            <td class="px-3 py-2">{{ $estimate->province?->label() }}</td>
                            <td class="px-3 py-2">{{ $estimate->licence_category?->label() }}</td>
                            <td class="px-3 py-2">{{ $estimate->tare_kg !== null ? number_format($estimate->tare_kg).' kg' : '—' }}</td>
                            <td class="px-3 py-2 text-xs">
                                @if ($estimate->needsConfirmation())
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-900">Confirmation required</span>
                                @else
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-900">Estimated</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-medium">
                                @if ($estimate->needsConfirmation())
                                    —
                                @else
                                    R {{ number_format($estimate->total_cents / 100, 2, '.', ' ') }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-muted">{{ $estimate->application?->reference ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                <button wire:click="view({{ $estimate->id }})" class="text-xs font-semibold text-ink hover:underline">View</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
