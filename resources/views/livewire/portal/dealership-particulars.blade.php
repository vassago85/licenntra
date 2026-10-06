<div>
    <div class="mb-4">
        <h1 class="text-xl font-semibold">Dealership details</h1>
        <p class="text-sm text-muted">
            Registration number, addresses, proxy and representative for <span class="font-medium">{{ $account->name }}</span>.
            The licensing company prints these on the RLV when you register a vehicle into stock or sign as the motor dealer.
            Upload the BRN certificate and the proxy letter with each application as usual.
        </p>
    </div>

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif

    @unless ($account->hasDealershipParticulars())
        <p class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Your registration number and proxy are not complete yet, so the licensing company has to fill them in by hand on each form.
        </p>
    @endunless

    <form wire:submit="save" class="space-y-4">
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="mb-3 text-sm font-semibold">Business</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-muted">Business registration number (BRN)</span>
                    <input wire:model="brn" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('brn') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <div></div>
                <label class="block text-sm">
                    <span class="text-muted">Contact e-mail</span>
                    <input wire:model="contact_email" type="email" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('contact_email') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Contact telephone during the day</span>
                    <input wire:model="contact_phone" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('contact_phone') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Street address</span>
                    <textarea wire:model="street_address" rows="3" placeholder="12 Main Road, Lynnwood, Pretoria, 0081" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm"></textarea>
                    <span class="mt-1 block text-xs text-muted">Separate the street, suburb, city and postal code with commas.</span>
                    @error('street_address') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Postal address (if different)</span>
                    <textarea wire:model="postal_address" rows="3" placeholder="PO Box 123, Lynnwood Ridge, Pretoria, 0040" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm"></textarea>
                    @error('postal_address') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        @foreach (['proxy' => ['Proxy', 'The person authorised by the dealership to sign the forms. Required.'], 'representative' => ['Representative', 'Only if different from the proxy.']] as $delegate => [$heading, $hint])
            <section class="rounded-md border border-line bg-white p-4" wire:key="delegate-{{ $delegate }}">
                <h2 class="text-sm font-semibold">{{ $heading }}</h2>
                <p class="mb-3 text-xs text-muted">{{ $hint }}</p>
                <div class="grid gap-3 md:grid-cols-4">
                    <label class="block text-sm md:col-span-2">
                        <span class="text-muted">Surname</span>
                        <input wire:model="{{ $delegate }}_name" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @error($delegate.'_name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Initials</span>
                        <input wire:model="{{ $delegate }}_initials" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @error($delegate.'_initials') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <div></div>
                    <label class="block text-sm">
                        <span class="text-muted">Type of identification</span>
                        <select wire:model="{{ $delegate }}_id_type" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            <option value="">Choose</option>
                            @foreach ($idTypes as $idType)
                                <option value="{{ $idType->value }}">{{ $idType->label() }}</option>
                            @endforeach
                        </select>
                        @error($delegate.'_id_type') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Identification number</span>
                        <input wire:model="{{ $delegate }}_id_number" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @error($delegate.'_id_number') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-muted">Country of issue (foreign ID)</span>
                        <input wire:model="{{ $delegate }}_id_country" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @error($delegate.'_id_country') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                </div>
            </section>
        @endforeach

        <div>
            <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Save dealership details</button>
        </div>
    </form>
</div>
