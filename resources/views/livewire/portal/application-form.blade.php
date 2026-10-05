<div>
<div class="mb-4">
    <h1 class="text-xl font-semibold">{{ $application ? $application->reference : 'New application' }}</h1>
    <p class="text-sm text-muted">The checklist and fee estimate update as you change the vehicle, owner, and finance.</p>
</div>

@if ($errors->any())
    <div class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm text-red-800">{{ $errors->first() }}</div>
@endif

<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
    <form wire:submit="save" class="space-y-4">
        <section class="grid gap-3 rounded-md border border-line bg-white p-3 sm:grid-cols-2">
            <label class="text-sm">Request
                <select wire:model.live="request_type" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                    <option value="">Choose</option>
                    @foreach ($requestTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Province
                <select wire:model.live="province" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                    <option value="">Choose</option>
                    @foreach ($provinces as $province)
                        <option value="{{ $province->value }}">{{ $province->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Vehicle category
                <select wire:model.live="vehicle_category" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                    <option value="">Choose</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->value }}">{{ $category->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Service
                <select wire:model.live="service_type" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                    <option value="">Choose</option>
                    @foreach ($serviceTypes as $service)
                        <option value="{{ $service->value }}">{{ $service->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-end gap-2 text-sm sm:col-span-2">
                <input type="checkbox" wire:model.live="dangerous_goods"> Dangerous goods
            </label>
            @if ($dangerous_goods)
                <p class="text-xs text-muted sm:col-span-2">The certificate of fitness must be stamped Dangerous goods.</p>
            @endif
        </section>

        <section class="grid gap-3 rounded-md border border-line bg-white p-3 sm:grid-cols-2">
            <h2 class="sm:col-span-2 text-sm font-semibold">Vehicle</h2>
            <label class="text-sm">VIN <input wire:model.blur="vin" class="mt-1 w-full rounded-md border border-line px-2 py-2 font-mono uppercase"></label>
            <label class="text-sm">NaTIS Vehicle Number <input wire:model.blur="vehicle_register_number" class="mt-1 w-full rounded-md border border-line px-2 py-2 font-mono uppercase" placeholder="e.g. YHF428W"></label>
            <label class="text-sm">Make <input wire:model.blur="make" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
            <label class="text-sm">Model <input wire:model.blur="model" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
            <label class="text-sm">Year <input wire:model.blur="year" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
            <label class="text-sm">Engine number <input wire:model.blur="engine_number" class="mt-1 w-full rounded-md border border-line px-2 py-2 font-mono"></label>
            <label class="text-sm">Body <input wire:model.blur="body_type" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
            <label class="text-sm">Tare kg <input wire:model.blur="tare_kg" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
        </section>

        <section class="space-y-3 rounded-md border border-line bg-white p-3">
            <h2 class="text-sm font-semibold">Owner</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">Owner type
                    <select wire:model.live="owner_type" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                        <option value="">Choose</option>
                        @foreach ($ownerTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-end gap-2 text-sm">
                    <input type="checkbox" wire:model.live="is_financed"> Financed, with a title holder
                </label>
            </div>
            @if ($owner_type === 'business')
                <label class="block text-sm">Saved business client
                    <select wire:model.live="business_client_id" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                        <option value="">Choose</option>
                        <option value="new">New business client</option>
                        @foreach ($owners as $owner)
                            <option value="{{ $owner->id }}">{{ $owner->business_name }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($business_client_id === 'new')
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Business name <input wire:model="new_business_name" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
                        <label class="text-sm">Registration number <input wire:model="new_registration_number" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
                        <label class="text-sm">Proxy name <input wire:model="new_proxy_name" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
                        <label class="text-sm">Proxy ID <input wire:model="new_proxy_id_number" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
                        <label class="text-sm sm:col-span-2">Address <input wire:model="new_address" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
                    </div>
                @endif
            @endif
            @if ($owner_type === 'individual')
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">Name <input wire:model.blur="owner_name" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
                    <label class="text-sm">ID number <input wire:model.blur="owner_identifier" class="mt-1 w-full rounded-md border border-line px-2 py-2 font-mono"></label>
                    <label class="text-sm sm:col-span-2">Address <input wire:model.blur="owner_address" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
                </div>
            @endif
            @if ($is_financed)
                <label class="block text-sm">Title holder
                    <select wire:model.live="title_holder_business_client_id" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                        <option value="">Choose</option>
                        @foreach ($titleHolders as $holder)
                            <option value="{{ $holder->id }}">{{ $holder->business_name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </section>

        <div class="flex gap-2">
            <button type="submit" class="h-9 rounded-md border border-line bg-white px-3 text-sm">Save draft</button>
            <button type="button" wire:click="submit" class="h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Submit</button>
        </div>
    </form>

    <aside class="space-y-4">
        <section class="rounded-md border border-line bg-white p-3">
            <h2 class="text-sm font-semibold">Documents</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($documents as $document)
                    <li class="flex items-start justify-between gap-3">
                        <span>{{ $document->label() }}</span>
                        <span class="font-mono text-xs text-muted">{{ $document->required ? $document->status->label() : 'Optional' }}</span>
                    </li>
                @empty
                    <li class="text-muted">Choose the request, category, and owner to build the checklist.</li>
                @endforelse
            </ul>
        </section>
        <section class="rounded-md border border-line bg-white p-3">
            <h2 class="text-sm font-semibold">Fee estimate</h2>
            @if ($estimate)
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($estimate['lines'] as $line)
                        @if ($line['client_visible'])
                            <li class="flex justify-between gap-3"><span>{{ $line['label'] }}</span><span class="font-mono">{{ $money::rands($line['amount_cents']) }}</span></li>
                        @endif
                    @endforeach
                </ul>
                <p class="mt-3 flex justify-between text-sm font-semibold"><span>Total</span><span class="font-mono">{{ $money::rands($estimate['total_cents']) }}</span></p>
            @else
                <p class="mt-3 text-sm text-muted">Choose a province to estimate fees.</p>
            @endif
        </section>
    </aside>
</div>
</div>
