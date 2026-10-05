<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Users</h1>
            <p class="text-sm text-muted">Licensing staff and dealer / fleet logins. Deactivate for temporary absences; offboard permanent leavers.</p>
        </div>
        @if (! $showForm)
            <button wire:click="create" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Add user</button>
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
                <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit user' : 'New user' }}</h2>
                <button wire:click="cancel" class="text-xs text-muted hover:underline">Cancel</button>
            </div>
            <form wire:submit="save" class="grid gap-3 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-muted">Full name</span>
                    <input wire:model="name" type="text" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Email</span>
                    <input wire:model="email" type="email" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('email') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Role</span>
                    <select wire:model.live="role" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        @foreach ($roleLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('role') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                @if (in_array($role, ['customer_admin', 'customer_user'], true))
                    <label class="block text-sm">
                        <span class="text-muted">Dealer / fleet account</span>
                        <select wire:model="clientAccountId" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                            <option value="">Choose…</option>
                            @foreach ($accounts as $id => $accountName)
                                <option value="{{ $id }}">{{ $accountName }}</option>
                            @endforeach
                        </select>
                        @error('clientAccountId') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                    </label>
                @else
                    <p class="self-end pb-2 text-xs text-muted">Staff roles are not tied to a dealer account.</p>
                @endif
                <label class="block text-sm">
                    <span class="text-muted">{{ $editingId ? 'New password (leave blank to keep)' : 'Initial password' }}</span>
                    <input wire:model="password" type="password" autocomplete="new-password" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('password') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="flex items-center gap-2 self-end pb-2 text-sm">
                    <input wire:model="isActive" type="checkbox"> Can sign in
                </label>
                <div class="md:col-span-2">
                    <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">{{ $editingId ? 'Save changes' : 'Create user' }}</button>
                </div>
            </form>
        </section>
    @endif

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Name, email or dealer" class="h-9 w-64 rounded-md border border-line bg-white px-2 text-sm">
        <select wire:model.live="roleFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">All roles</option>
            @foreach ($roleLabels as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="statusFilter" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
            <option value="">Any status</option>
            <option value="active">Active</option>
            <option value="deactivated">Deactivated</option>
            <option value="offboarded">Offboarded</option>
            <option value="anonymised">Anonymised</option>
        </select>
    </div>

    <section class="overflow-hidden rounded-md border border-line bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-paper text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">User</th>
                        <th class="px-3 py-2 font-medium">Role</th>
                        <th class="px-3 py-2 font-medium">Account</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($users as $row)
                        <tr wire:key="user-{{ $row->id }}" class="align-top">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $row->name }}</div>
                                <div class="font-mono text-xs text-muted">{{ $row->email }}</div>
                            </td>
                            <td class="px-3 py-2">
                                @forelse ($row->roles as $r)
                                    <span class="inline-block rounded-full border border-line px-2 py-0.5 text-xs">{{ $roleLabels[$r->name] ?? $r->name }}</span>
                                @empty
                                    <span class="text-xs text-muted">—</span>
                                @endforelse
                            </td>
                            <td class="px-3 py-2 text-xs">{{ $row->clientAccount?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">
                                @if ($row->isAnonymised())
                                    <span class="text-muted">Anonymised</span>
                                    <div class="text-muted">PII scrubbed {{ $row->anonymised_at?->format('d M Y') }}</div>
                                @elseif ($row->isOffboarded())
                                    <span class="font-medium text-red-800">Offboarded</span>
                                    <div class="text-muted">{{ $row->offboard_reason?->label() }} · purges {{ $row->retentionEndsAt()?->format('d M Y') }}</div>
                                @elseif ($row->is_active)
                                    <span class="text-emerald-700">Active</span>
                                @else
                                    <span class="text-amber-800">Deactivated</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                @if ($offboardingId === $row->id)
                                    <form wire:submit="confirmOffboarding" class="ml-auto flex max-w-md flex-col gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-xs">
                                        <p class="text-red-900">Revokes access, strips roles, kills sessions and 2FA, and starts the 5-year retention clock. This cannot be undone.</p>
                                        <select wire:model="offboardReason" class="h-8 rounded-md border border-line bg-white px-2">
                                            <option value="">Reason…</option>
                                            @foreach ($offboardReasons as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @error('offboardReason') <span class="text-red-800">{{ $message }}</span> @enderror
                                        <textarea wire:model="offboardNote" rows="2" placeholder="Note (optional, kept with the audit record)" class="rounded-md border border-line bg-white px-2 py-1"></textarea>
                                        <div class="flex gap-2">
                                            <button type="submit" class="h-8 rounded-md bg-red-700 px-3 font-semibold text-white">Offboard</button>
                                            <button type="button" wire:click="cancelOffboarding" class="text-muted hover:underline">Cancel</button>
                                        </div>
                                    </form>
                                @elseif (! $row->isOffboarded())
                                    <div class="flex flex-wrap items-center justify-end gap-3 text-xs">
                                        <button wire:click="edit({{ $row->id }})" class="hover:underline">Edit</button>
                                        @if ($row->id !== $currentUserId)
                                            @if ($row->is_active)
                                                <button wire:click="deactivate({{ $row->id }})" wire:confirm="Deactivate {{ $row->name }}? They cannot sign in until reactivated." class="text-amber-800 hover:underline">Deactivate</button>
                                            @else
                                                <button wire:click="activate({{ $row->id }})" class="text-emerald-700 hover:underline">Activate</button>
                                            @endif
                                            @if ($row->isLicensingStaff())
                                                <button wire:click="startOffboarding({{ $row->id }})" class="text-red-700 hover:underline">Offboard</button>
                                            @endif
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-10 text-center text-muted">No users match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="border-t border-line px-3 py-2">{{ $users->links() }}</div>
        @endif
    </section>
</div>
