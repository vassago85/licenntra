<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Client accounts</h1>
            <p class="text-sm text-muted">Dealers, OEMs, body builders and fleet operators. Billing mode decides whether fees are paid up-front or added to a monthly statement.</p>
        </div>
        @if (! $showForm)
            <button wire:click="create" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Add account</button>
        @endif
    </div>

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif
    @if ($errorMessage)
        <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $errorMessage }}</p>
    @endif

    @if ($showForm)
        <section class="mb-6 rounded-md border border-line bg-white p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit account' : 'New account' }}</h2>
                <button wire:click="cancel" class="text-xs text-muted hover:underline">Cancel</button>
            </div>
            <form wire:submit="save" class="space-y-5">
                <fieldset class="grid gap-3 md:grid-cols-3">
                    <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Account</legend>
                    <label class="block text-sm md:col-span-2">
                        <span class="text-muted">Name</span>
                        <input wire:model="name" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @error('name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Status</span>
                        <select wire:model="status" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            @foreach ($statusOptions as $option)
                                <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                            @endforeach
                        </select>
                        @error('status') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Primary type</span>
                        <select wire:model.live="type" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            @foreach ($types as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        @error('type') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <div class="text-sm md:col-span-2">
                        <span class="text-muted">Also operates as</span>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ($types as $case)
                                @if ($case->value !== $type)
                                    <label class="flex items-center gap-1.5">
                                        <input wire:model="additionalTypes" type="checkbox" value="{{ $case->value }}"> {{ $case->label() }}
                                    </label>
                                @endif
                            @endforeach
                        </div>
                    </div>
                    <label class="block text-sm">
                        <span class="text-muted">Business registration number</span>
                        <input wire:model="brn" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @error('brn') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm md:col-span-2">
                        <span class="text-muted">Primary reviewer</span>
                        <select wire:model="primaryReviewerId" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            <option value="">General queue (no fixed reviewer)</option>
                            @foreach ($reviewers as $id => $reviewerName)
                                <option value="{{ $id }}">{{ $reviewerName }}</option>
                            @endforeach
                        </select>
                        @error('primaryReviewerId') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                </fieldset>

                <fieldset class="grid gap-3 md:grid-cols-3">
                    <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Pricing &amp; billing</legend>
                    <label class="block text-sm">
                        <span class="text-muted">Markup (basis points)</span>
                        <input wire:model="markupBasisPoints" type="number" min="0" max="10000" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        <span class="mt-1 block text-xs text-muted">100 bp = 1%.</span>
                        @error('markupBasisPoints') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Billing mode</span>
                        <select wire:model.live="billingMode" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            @foreach ($billingModes as $mode)
                                <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                            @endforeach
                        </select>
                        @error('billingMode') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex items-center gap-2 self-center text-sm">
                        <input wire:model="quoteAcceptanceAllowed" type="checkbox"> Client may accept quotes in the portal
                    </label>
                    <label class="flex items-start gap-2 self-center text-sm">
                        <input wire:model="hasStandingAgreement" type="checkbox" class="mt-1">
                        <span>
                            Standing agreement
                            <span class="block text-xs text-muted">Quotes sent to this client count as accepted straight away, and any application can go to payment without a quote.</span>
                        </span>
                    </label>
                    @if ($billingMode === 'account_statement')
                        <label class="block text-sm">
                            <span class="text-muted">Payment terms (days)</span>
                            <input wire:model="paymentTermsDays" type="number" min="0" max="120" placeholder="30" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            @error('paymentTermsDays') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                        </label>
                        <label class="block text-sm">
                            <span class="text-muted">Credit limit (R)</span>
                            <input wire:model="creditLimitRands" type="number" min="0" step="0.01" placeholder="No limit" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            @error('creditLimitRands') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                        </label>
                    @endif
                </fieldset>

                <fieldset class="grid gap-3 md:grid-cols-3">
                    <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Contact</legend>
                    <label class="block text-sm">
                        <span class="text-muted">Contact name</span>
                        <input wire:model="contactName" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @error('contactName') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Contact email</span>
                        <input wire:model="contactEmail" type="email" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @error('contactEmail') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Contact phone</span>
                        <input wire:model="contactPhone" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @error('contactPhone') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                </fieldset>

                <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">{{ $editingId ? 'Save changes' : 'Create account' }}</button>
            </form>
        </section>
    @endif

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Name, contact or BRN" class="h-9 w-64 rounded-md border border-line bg-white px-2 text-sm">
        <select wire:model.live="typeFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">All types</option>
            @foreach ($types as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
            @endforeach
        </select>
    </div>

    <section class="overflow-hidden rounded-md border border-line bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Account</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Billing</th>
                        <th class="px-3 py-2 font-medium">Reviewer</th>
                        <th class="px-3 py-2 font-medium">Contact</th>
                        <th class="px-3 py-2 font-medium text-right">Apps / users</th>
                        <th class="px-3 py-2 font-medium text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($accounts as $account)
                        <tr wire:key="account-{{ $account->id }}" class="align-top">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $account->name }}</div>
                                <div class="text-xs {{ $account->status === 'active' ? 'text-emerald-700' : 'text-amber-800' }}">{{ ucfirst((string) $account->status) }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                {{ $account->type?->label() }}
                                @if (! empty($account->additional_types))
                                    <div class="text-muted">+ {{ collect($account->additional_types)->map(fn ($value) => \App\Enums\ClientAccountType::tryFrom($value)?->label() ?? $value)->join(', ') }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs">
                                {{ $account->billing_mode?->shortLabel() ?? 'Up-front' }}
                                @if ($account->markup_basis_points)
                                    <div class="text-muted">+{{ number_format($account->markup_basis_points / 100, 2) }}% markup</div>
                                @endif
                                @if (! $account->quote_acceptance_allowed)
                                    <div class="text-muted">Staff accept quotes</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs">{{ $account->primaryReviewer?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">
                                <div>{{ $account->contact_name ?? '—' }}</div>
                                <div class="text-muted">{{ $account->contact_email }}</div>
                            </td>
                            <td class="px-3 py-2 text-right text-xs tabular-nums">{{ $account->applications_count }} / {{ $account->users_count }}</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3 text-xs">
                                    <button wire:click="edit({{ $account->id }})" class="hover:underline">Edit</button>
                                    @if ($account->applications_count === 0 && $account->users_count === 0)
                                        <button wire:click="delete({{ $account->id }})" wire:confirm="Delete {{ $account->name }}?" class="text-red-700 hover:underline">Delete</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-10 text-center text-muted">No accounts match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($accounts->hasPages())
            <div class="border-t border-line px-3 py-2">{{ $accounts->links() }}</div>
        @endif
    </section>
</div>
