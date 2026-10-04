<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Team</h1>
            <p class="text-sm text-muted">Add staff who sign in for <span class="font-medium">{{ $account?->name }}</span>.</p>
        </div>
        @if (! $showCreate)
            <button wire:click="openCreate" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Add team member</button>
        @endif
    </div>

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $statusMessage }}</p>
    @endif

    @if ($showCreate)
        <section class="mb-6 rounded-md border border-line bg-white p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">New team member</h2>
                <button wire:click="cancelCreate" class="text-xs text-muted hover:underline">Cancel</button>
            </div>
            <form wire:submit="createMember" class="grid gap-3 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-muted">Full name</span>
                    <input wire:model="name" type="text" required class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Email</span>
                    <input wire:model="email" type="email" required class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('email') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Role</span>
                    <select wire:model="role" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                        <option value="client_user">User &mdash; everyday work</option>
                        <option value="client_admin">Admin &mdash; can manage this team</option>
                    </select>
                    @error('role') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <div></div>
                <label class="block text-sm">
                    <span class="text-muted">Initial password</span>
                    <input wire:model="password" type="password" required minlength="8" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('password') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Confirm password</span>
                    <input wire:model="password_confirmation" type="password" required minlength="8" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                </label>
                <div class="md:col-span-2">
                    <p class="mb-2 text-xs text-muted">You'll need to share this password with the new user securely. They can change it from their account page after signing in.</p>
                    <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Create account</button>
                </div>
            </form>
        </section>
    @endif

    <section class="rounded-md border border-line bg-white">
        <table class="w-full text-left text-sm">
            <thead class="text-xs text-muted">
                <tr class="border-b border-line">
                    <th class="px-3 py-2 font-medium">Name</th>
                    <th class="px-3 py-2 font-medium">Email</th>
                    <th class="px-3 py-2 font-medium">Role</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                    <th class="px-3 py-2 font-medium text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($members as $member)
                    <tr class="border-b border-line last:border-0 align-top">
                        <td class="px-3 py-2">{{ $member->name }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $member->email }}</td>
                        <td class="px-3 py-2">
                            @foreach ($member->roles as $r)
                                <span class="inline-block rounded-full border border-line px-2 py-0.5 text-xs">
                                    {{ $r->name === 'client_admin' ? 'Admin' : 'User' }}
                                </span>
                            @endforeach
                        </td>
                        <td class="px-3 py-2">
                            @if ($member->is_active)
                                <span class="text-emerald-700">Active</span>
                            @else
                                <span class="text-muted">Disabled</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap items-center justify-end gap-3 text-xs">
                                @if ($resettingUserId === $member->id)
                                    <form wire:submit="completePasswordReset" class="flex flex-wrap items-center gap-2">
                                        <input wire:model="resetPassword" type="password" placeholder="New password" minlength="8" required class="h-8 rounded-md border border-line px-2">
                                        <input wire:model="resetPasswordConfirmation" type="password" placeholder="Confirm" minlength="8" required class="h-8 rounded-md border border-line px-2">
                                        <button type="submit" class="h-8 rounded-md px-3 font-semibold text-white" style="background: var(--brand)">Save</button>
                                        <button type="button" wire:click="cancelPasswordReset" class="text-muted hover:underline">Cancel</button>
                                        @error('resetPassword') <span class="w-full text-red-800">{{ $message }}</span> @enderror
                                        @error('resetPasswordConfirmation') <span class="w-full text-red-800">{{ $message }}</span> @enderror
                                    </form>
                                @else
                                    <button wire:click="startPasswordReset({{ $member->id }})" class="hover:underline">Reset password</button>
                                    @if ($member->is_active)
                                        <button wire:click="deactivate({{ $member->id }})" class="text-red-700 hover:underline">Deactivate</button>
                                    @else
                                        <button wire:click="activate({{ $member->id }})" class="text-emerald-700 hover:underline">Activate</button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-6 text-muted">No team members yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
