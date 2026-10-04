<div>
    <div class="mb-6 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold">Account settings</h1>
            <p class="text-sm text-muted">{{ $user->email }}</p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-sm font-semibold">Profile</h2>
            <p class="mt-1 text-xs text-muted">Your name is shown on reviews, audit log entries, and notifications.</p>

            @if ($profileStatus)
                <p class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $profileStatus }}</p>
            @endif

            <form wire:submit="updateProfile" class="mt-4 space-y-3">
                <label class="block text-sm">
                    <span class="text-muted">Full name</span>
                    <input wire:model="name" type="text" required class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('name') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Email address</span>
                    <input wire:model="email" type="email" required class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('email') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <div class="pt-2">
                    <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Save profile</button>
                </div>
            </form>
        </section>

        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-sm font-semibold">Password</h2>
            <p class="mt-1 text-xs text-muted">Use at least 8 characters. You will stay signed in on this device.</p>

            @if ($passwordStatus)
                <p class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ $passwordStatus }}</p>
            @endif

            <form wire:submit="updatePassword" class="mt-4 space-y-3">
                <label class="block text-sm">
                    <span class="text-muted">Current password</span>
                    <input wire:model="current_password" type="password" autocomplete="current-password" required class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('current_password') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">New password</span>
                    <input wire:model="password" type="password" autocomplete="new-password" required minlength="8" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                    @error('password') <span class="mt-1 block text-xs text-red-800">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="text-muted">Confirm new password</span>
                    <input wire:model="password_confirmation" type="password" autocomplete="new-password" required minlength="8" class="mt-1 h-10 w-full rounded-md border border-line bg-white px-3 text-sm">
                </label>
                <div class="pt-2">
                    <button type="submit" class="h-10 rounded-md px-4 text-sm font-semibold text-white" style="background: var(--brand)">Update password</button>
                </div>
            </form>
        </section>
    </div>

    <section class="mt-6 rounded-md border border-line bg-white p-4 text-sm">
        <h2 class="font-semibold">Two-factor authentication</h2>
        <p class="mt-1 text-xs text-muted">
            @if ($twoFactorEnabled)
                Two-factor is <span class="font-semibold text-emerald-700">enabled</span>. You confirmed it on {{ $user->two_factor_confirmed_at?->format('d M Y') }}.
            @else
                Two-factor is <span class="font-semibold">not enabled</span>. Ask your administrator to help you enrol if you handle approvals or payments.
            @endif
        </p>
    </section>
</div>
