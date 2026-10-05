<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Document library</h1>
            <p class="text-sm text-muted">A rule applies when every condition matches the application. Blank conditions mean "any".</p>
        </div>
        @if (! $showForm)
            <button wire:click="create" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Add rule</button>
        @endif
    </div>

    @include('livewire.portal.admin.partials.section-tabs', ['tabs' => ['admin.document-rules' => 'Rules', 'admin.document-types' => 'Document types']])

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif

    @if ($showForm)
        <section class="mb-6 rounded-md border border-line bg-white p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit rule' : 'New rule' }}</h2>
                <button wire:click="cancel" class="text-xs text-muted hover:underline">Cancel</button>
            </div>
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-3 md:grid-cols-4">
                    <label class="block text-sm md:col-span-2">
                        <span class="text-muted">Document</span>
                        <select wire:model="documentTypeId" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            <option value="">Choose…</option>
                            @foreach ($documentTypes as $id => $typeName)
                                <option value="{{ $id }}">{{ $typeName }}</option>
                            @endforeach
                        </select>
                        @error('documentTypeId') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Collected from</span>
                        <select wire:model="partyRole" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            @foreach ($partyRoles as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('partyRole') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Requirement</span>
                        <select wire:model="requirement" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            @foreach ($requirements as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('requirement') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                </div>

                <fieldset class="grid gap-3 md:grid-cols-3 lg:grid-cols-6">
                    <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Applies when</legend>
                    <label class="block text-sm">
                        <span class="text-muted">Request type</span>
                        <select wire:model="requestType" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-2 text-sm">
                            <option value="">Any</option>
                            @foreach ($requestTypes as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        @error('requestType') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Vehicle</span>
                        <select wire:model="vehicleCategory" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-2 text-sm">
                            <option value="">Any</option>
                            @foreach ($vehicleCategories as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Owner</span>
                        <select wire:model="ownerType" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-2 text-sm">
                            <option value="">Any</option>
                            @foreach ($ownerTypes as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Province</span>
                        <select wire:model="province" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-2 text-sm">
                            <option value="">Any</option>
                            @foreach ($provinces as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Financed</span>
                        <select wire:model="isFinanced" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-2 text-sm">
                            <option value="">Any</option>
                            <option value="1">Financed</option>
                            <option value="0">Cash</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Dealer stock</span>
                        <select wire:model="isDealerStock" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-2 text-sm">
                            <option value="">Any</option>
                            <option value="1">Dealer stock</option>
                            <option value="0">Not dealer stock</option>
                        </select>
                    </label>
                </fieldset>

                <div class="flex flex-wrap items-end gap-4">
                    <label class="block text-sm">
                        <span class="text-muted">Sort order</span>
                        <input wire:model="sortOrder" type="number" min="0" class="mt-1 h-10 w-28 rounded-md border border-line bg-white px-3 text-sm">
                        @error('sortOrder') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex items-center gap-2 pb-2 text-sm">
                        <input wire:model="active" type="checkbox"> Active
                    </label>
                    <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">{{ $editingId ? 'Save changes' : 'Add rule' }}</button>
                </div>
            </form>
        </section>
    @endif

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <select wire:model.live="requestTypeFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">All request types</option>
            @foreach ($requestTypes as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="documentTypeFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">All documents</option>
            @foreach ($documentTypes as $id => $typeName)
                <option value="{{ $id }}">{{ $typeName }}</option>
            @endforeach
        </select>
        <span class="text-xs text-muted">{{ $rules->count() }} {{ \Illuminate\Support\Str::plural('rule', $rules->count()) }}</span>
    </div>

    <section class="overflow-hidden rounded-md border border-line bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Request</th>
                        <th class="px-3 py-2 font-medium">Document</th>
                        <th class="px-3 py-2 font-medium">Conditions</th>
                        <th class="px-3 py-2 font-medium">Requirement</th>
                        <th class="px-3 py-2 font-medium text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($rules as $rule)
                        @php
                            $conditions = array_filter([
                                $rule->vehicle_category?->label(),
                                $rule->owner_type?->label(),
                                $rule->province?->label(),
                                $rule->is_financed === null ? null : ((bool) $rule->is_financed ? 'Financed' : 'Cash'),
                                $rule->is_dealer_stock === null ? null : ($rule->is_dealer_stock ? 'Dealer stock' : 'Not dealer stock'),
                            ]);
                        @endphp
                        <tr wire:key="rule-{{ $rule->id }}" class="{{ $rule->active ? '' : 'opacity-50' }}">
                            <td class="px-3 py-2 text-xs">{{ $rule->request_type?->label() ?? 'Any request' }}</td>
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $rule->documentType?->name }}</div>
                                <div class="text-xs text-muted">From {{ strtolower($partyRoles[$rule->party_role] ?? $rule->party_role) }} · #{{ $rule->sort_order }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                @forelse ($conditions as $condition)
                                    <span class="mr-1 inline-block rounded-full border border-line px-2 py-0.5">{{ $condition }}</span>
                                @empty
                                    <span class="text-muted">Always</span>
                                @endforelse
                            </td>
                            <td class="px-3 py-2 text-xs">
                                <span class="{{ $rule->requirement === 'required' ? 'font-medium text-ink' : 'text-muted' }}">{{ $requirements[$rule->requirement] ?? $rule->requirement }}</span>
                                @unless ($rule->active)
                                    <div class="text-muted">Disabled</div>
                                @endunless
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-3 text-xs">
                                    <button wire:click="edit({{ $rule->id }})" class="hover:underline">Edit</button>
                                    <button wire:click="toggleActive({{ $rule->id }})" class="hover:underline">{{ $rule->active ? 'Disable' : 'Enable' }}</button>
                                    <button wire:click="delete({{ $rule->id }})" wire:confirm="Delete this rule?" class="text-red-700 hover:underline">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-10 text-center text-muted">No rules match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
