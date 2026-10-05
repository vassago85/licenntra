<div>
    <div class="mb-4">
        <a href="{{ route('business-clients.index') }}" class="text-sm text-muted">&larr; Business clients</a>
        <h1 class="mt-1 text-xl font-semibold">
            {{ $businessClient !== null ? 'Edit '.$businessClient->business_name : 'Add business client' }}
        </h1>
        <p class="text-sm text-muted">
            This record stays scoped to your dealership account. Registration and ID numbers are stored encrypted at rest.
        </p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">
            <p class="font-semibold">Please fix the following:</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="save" class="grid gap-4 lg:grid-cols-5">
        <section class="rounded-md border border-line bg-white p-4 lg:col-span-3">
            <h2 class="mb-3 text-sm font-semibold">Business</h2>

            <div class="grid gap-3">
                <label class="block text-sm">
                    <span class="text-muted">Business name <span class="text-red-700">*</span></span>
                    <input wire:model="business_name" type="text" required maxlength="160"
                           class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('business_name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Registration number</span>
                    <input wire:model="registration_number" type="text" maxlength="64"
                           class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <span class="mt-1 block text-xs text-muted">CIPC / company registration number. Stored encrypted.</span>
                    @error('registration_number') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Address</span>
                    <textarea wire:model="address" rows="2" maxlength="500"
                              class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm"></textarea>
                    @error('address') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="text-muted">Use <span class="text-red-700">*</span></span>
                        <select wire:model.live="usable_as" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            <option value="owner">Owner</option>
                            <option value="title_holder">Title holder</option>
                            <option value="both">Both (owner and title holder)</option>
                        </select>
                        <span class="mt-1 block text-xs text-muted">Controls where this business can be picked on an application.</span>
                        @error('usable_as') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>

                    <label class="block text-sm">
                        <span class="text-muted">Status <span class="text-red-700">*</span></span>
                        <select wire:model="status" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        @error('status') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                </div>

                @if (in_array($usable_as, ['title_holder', 'both'], true))
                    <label class="flex items-start gap-2 rounded-md border border-dashed border-line bg-paper p-3 text-sm">
                        <input type="checkbox" wire:model="is_shared" class="mt-0.5">
                        <span>
                            <span class="font-semibold">Share with other dealerships</span>
                            <span class="mt-1 block text-xs text-muted">
                                Finance houses and banks are typically re-used across dealerships. Ticking this makes the record visible (and editable) by every dealership on the platform. Owner records can never be shared.
                            </span>
                        </span>
                    </label>
                @endif
            </div>
        </section>

        <section class="rounded-md border border-line bg-white p-4 lg:col-span-2">
            <h2 class="mb-3 text-sm font-semibold">Representative (proxy)</h2>

            <div class="grid gap-3">
                <label class="block text-sm">
                    <span class="text-muted">Proxy name</span>
                    <input wire:model="proxy_name" type="text" maxlength="160"
                           class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('proxy_name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Proxy contact</span>
                    <input wire:model="proxy_contact" type="text" maxlength="160"
                           class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm"
                           placeholder="Email or phone">
                    @error('proxy_contact') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Proxy ID number</span>
                    <input wire:model="proxy_id_number" type="text" maxlength="32"
                           class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <span class="mt-1 block text-xs text-muted">Stored encrypted at rest.</span>
                    @error('proxy_id_number') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <div class="flex items-center justify-end gap-2 lg:col-span-5">
            @if ($businessClient !== null)
                <a href="{{ route('business-clients.show', $businessClient) }}"
                   class="h-10 rounded-md border border-line bg-white px-4 text-sm font-semibold leading-10">Cancel</a>
            @else
                <a href="{{ route('business-clients.index') }}"
                   class="h-10 rounded-md border border-line bg-white px-4 text-sm font-semibold leading-10">Cancel</a>
            @endif
            <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">
                {{ $businessClient !== null ? 'Save changes' : 'Add business client' }}
            </button>
        </div>
    </form>
</div>
