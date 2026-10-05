<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Branding</h1>
            <p class="text-sm text-muted">What your clients see across the portal, email and PDF deliverables.</p>
        </div>
    </div>

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-md border border-line bg-white p-4">
            <header class="mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-4 w-4 text-muted">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21"/>
                </svg>
                <h2 class="text-sm font-semibold">Identity</h2>
            </header>

            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-muted">Company name</span>
                    <input wire:model="company_name" type="text" required maxlength="120"
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    <small class="mt-1 block text-xs text-muted">Appears in the header, email and application PDFs.</small>
                    @error('company_name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="text-muted">Reference prefix</span>
                    <input wire:model="reference_prefix" type="text" required maxlength="12"
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 font-mono text-sm uppercase">
                    <small class="mt-1 block text-xs text-muted">Example: <span class="font-mono">SUS</span> gives <span class="font-mono">SUS-2026-00001</span>.</small>
                    @error('reference_prefix') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <span class="block text-sm text-muted">Logo</span>
                    <div class="mt-1 flex items-start gap-3">
                        <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-md border border-line bg-paper">
                            @if ($logo_upload)
                                <img src="{{ $logo_upload->temporaryUrl() }}" alt="New logo preview" class="h-full w-full object-contain">
                            @elseif ($logo_path)
                                <img src="{{ asset('storage/'.$logo_path) }}" alt="Current logo" class="h-full w-full object-contain">
                            @else
                                <span class="text-xs text-muted">None</span>
                            @endif
                        </div>
                        <div class="flex flex-col gap-2 text-sm">
                            <input wire:model="logo_upload" type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                class="text-xs">
                            @if ($logo_path && ! $logo_upload)
                                <button type="button" wire:click="removeLogo"
                                    class="w-fit text-xs text-red-700 hover:underline">Remove logo</button>
                            @endif
                            <small class="text-xs text-muted">PNG, SVG, JPEG or WebP. Up to 1&nbsp;MB.</small>
                        </div>
                    </div>
                    @error('logo_upload') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </div>

                <label class="block text-sm">
                    <span class="text-muted">Primary colour</span>
                    <div class="mt-1 flex items-center gap-2">
                        <input wire:model="primary_colour" type="color" required
                            class="h-10 w-14 cursor-pointer rounded-md border border-line bg-white">
                        <input wire:model="primary_colour" type="text" required pattern="^#[0-9A-Fa-f]{6}$"
                            class="h-10 w-32 rounded-md border border-line bg-white px-3 font-mono text-sm uppercase">
                    </div>
                    <small class="mt-1 block text-xs text-muted">Used for buttons, badges and the sign-in page.</small>
                    @error('primary_colour') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <section class="rounded-md border border-line bg-white p-4">
            <header class="mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-4 w-4 text-muted">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>
                </svg>
                <h2 class="text-sm font-semibold">Support contact</h2>
            </header>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-muted">Support email</span>
                    <input wire:model="support_email" type="email" maxlength="160"
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('support_email') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Support phone</span>
                    <input wire:model="support_phone" type="tel" maxlength="40"
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('support_phone') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm md:col-span-2">
                    <span class="text-muted">Street address</span>
                    <input wire:model="address" type="text" maxlength="500"
                        class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('address') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <div class="flex items-center justify-end gap-2">
            <button type="submit"
                class="h-10 rounded-md px-5 text-sm font-semibold text-white"
                style="background: var(--brand);"
                wire:loading.attr="disabled"
                wire:target="save">
                <span wire:loading.remove wire:target="save">Save branding</span>
                <span wire:loading wire:target="save">Saving&hellip;</span>
            </button>
        </div>
    </form>
</div>
