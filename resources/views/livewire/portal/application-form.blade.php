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
            <label class="text-sm sm:col-span-2">Vehicle type for licensing
                <select wire:model.live="licence_category" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                    <option value="">Default (Rigid vehicle)</option>
                    @foreach ($licenceCategories as $category)
                        <option value="{{ $category->value }}">{{ $category->label() }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-muted">Picks the gazette column the fee estimate prices against. Default covers cars, bakkies, and rigid trucks.</span>
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
                @if ($showsTitleHolderPrompt)
                    <label class="flex items-end gap-2 text-sm">
                        <input type="checkbox" wire:model.live="is_financed"> Financed, with a title holder
                    </label>
                @else
                    <p class="self-end text-xs text-muted">
                        Title holder not required for {{ $request_type ? \App\Enums\RequestType::from($request_type)->label() : 'this request' }} - eNaTIS already has it on record.
                    </p>
                @endif
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
            @if ($is_financed && $showsTitleHolderPrompt)
                <label class="block text-sm">Title holder
                    <select wire:model.live="title_holder_business_client_id" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                        <option value="">Choose</option>
                        <option value="new">+ New title holder</option>
                        @foreach ($titleHolders as $holder)
                            <option value="{{ $holder->id }}">{{ $holder->business_name }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($title_holder_business_client_id === 'new')
                    <div class="grid gap-3 rounded-md border border-dashed border-line bg-paper p-3 sm:grid-cols-2">
                        <p class="text-xs text-muted sm:col-span-2">
                            Finance houses are usually captured once and re-used. Required for the RLV; proxy details are only needed when the title holder appoints a representative at the licensing authority.
                        </p>
                        <label class="text-sm">Business name
                            <input wire:model.blur="new_title_holder_business_name" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                        </label>
                        <label class="text-sm">BRN (CIPC registration number)
                            <input wire:model.blur="new_title_holder_registration_number" class="mt-1 w-full rounded-md border border-line px-2 py-2 font-mono"
                                   placeholder="e.g. 1962/000738/06">
                        </label>
                        <label class="text-sm">Proxy name
                            <input wire:model.blur="new_title_holder_proxy_name" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                        </label>
                        <label class="text-sm">Proxy contact
                            <input wire:model.blur="new_title_holder_proxy_contact" class="mt-1 w-full rounded-md border border-line px-2 py-2"
                                   placeholder="Email or phone">
                        </label>
                        <label class="text-sm">Proxy ID number
                            <input wire:model.blur="new_title_holder_proxy_id_number" class="mt-1 w-full rounded-md border border-line px-2 py-2 font-mono">
                        </label>
                        <label class="text-sm sm:col-span-2">Address
                            <input wire:model.blur="new_title_holder_address" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                        </label>
                        <p class="text-xs text-muted sm:col-span-2">
                            This record is saved to your Business clients list as a title holder and can be re-used on later applications.
                        </p>
                    </div>
                @endif
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

            @error('upload')
                <p class="mt-2 rounded-md border border-red-200 bg-red-50 px-2 py-1 text-xs text-red-900">{{ $message }}</p>
            @enderror

            @if ($application === null)
                <p class="mt-3 text-sm text-muted">Save the draft to build the checklist. Uploads become available immediately after.</p>
            @else
                <ul class="mt-3 space-y-3 text-sm">
                    @forelse ($documents as $document)
                        @php
                            $hasFile = $document->currentVersion !== null;
                            $statusLabel = $document->required ? $document->status->label() : 'Optional';
                            $badgeColor = match (true) {
                                $hasFile => 'text-emerald-900',
                                $document->required => 'text-red-800',
                                default => 'text-muted',
                            };
                        @endphp
                        <li class="rounded-md border border-line p-2">
                            <div class="flex items-start justify-between gap-3">
                                <span class="font-medium">{{ $document->label() }}</span>
                                <span class="font-mono text-xs {{ $badgeColor }}">{{ $hasFile ? 'Uploaded' : $statusLabel }}</span>
                            </div>

                            @if ($hasFile && $canDownloadDocument($document))
                                <p class="mt-1 text-xs text-muted">
                                    <a class="text-ink hover:underline" href="{{ route('documents.download', $document->currentVersion) }}">{{ $document->currentVersion->original_filename }}</a>
                                </p>
                            @endif

                            @if ($canUploadDocument($document))
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <input type="file" wire:model="uploads.{{ $document->id }}" accept=".pdf,.jpg,.jpeg,.png" class="min-w-0 text-xs">
                                    <button type="button" wire:click="upload({{ $document->id }})" class="h-8 shrink-0 rounded-md border border-line bg-white px-2 text-xs font-semibold hover:bg-paper">
                                        {{ $hasFile ? 'Replace' : 'Upload' }}
                                    </button>
                                </div>
                                <p class="mt-1 text-[11px] text-muted">PDF, JPG, or PNG · max 15 MB</p>
                                @error("uploads.{$document->id}")
                                    <p class="mt-1 text-xs text-red-800">{{ $message }}</p>
                                @enderror
                            @endif
                        </li>
                    @empty
                        <li class="text-muted">Choose the request, category, and owner to build the checklist.</li>
                    @endforelse
                </ul>
            @endif
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
