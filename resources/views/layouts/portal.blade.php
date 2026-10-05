<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $branding = $branding ?? \App\Models\BrandingSetting::current();
        $brand = $branding->primary_colour ?? '#146d61';
    @endphp
    <title>{{ $title ?? $branding->company_name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        :root {
            --brand: {{ $brand }};
            --brand-soft: #eaf4f0;
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
@php
    /** @var \App\Models\User|null $user */
    $user = auth()->user();
    $contextRole = null;
    if ($user) {
        $primaryRole = collect($user->getRoleNames())->first();
        $contextRole = match ($primaryRole) {
            'super_admin' => 'Company superadmin',
            'customer_admin' => 'Customer admin',
            'reviewer' => 'Reviewer',
            'finance' => 'Finance',
            'auditor' => 'Auditor',
            'client_admin' => 'Client admin',
            'client_user' => 'Client user',
            'developer' => 'Developer',
            default => $primaryRole ? ucfirst((string) $primaryRole) : null,
        };
    }
    $contextLabel = $user?->isClient() ? 'Client workspace' : 'Operations & administration';
@endphp
<body class="min-h-screen bg-paper text-ink antialiased">
<a class="sr-only focus:not-sr-only focus:absolute focus:left-3 focus:top-3 focus:z-50 focus:bg-ink focus:px-3 focus:py-2 focus:text-white" href="#content">Skip to content</a>

<div x-data="{ open: false }" class="min-h-screen">
    <div class="hidden items-center justify-between gap-3 border-b border-line bg-surface px-5 py-[9px] text-[11px] text-muted lg:flex">
        <span class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z"/></svg>
            <span>{{ $branding->company_name }}</span>
            @if ($contextRole)
                <span class="mx-1 opacity-60">/</span>
                <span>{{ $contextRole }}</span>
            @endif
        </span>
        <span>
            {{ ucfirst($branding->company_name) }}
            <strong class="ml-1 font-medium text-ink">{{ $contextLabel }}</strong>
        </span>
    </div>

    <div class="min-h-screen lg:pl-[176px]">
        <x-portal.sidebar :branding="$branding" />

        <div class="flex min-h-screen flex-col">
            <header class="sticky top-0 z-10 flex h-12 items-center gap-3 border-b border-line bg-surface px-4 lg:hidden">
                <button
                    type="button"
                    class="rounded-[4px] p-1 text-ink hover:bg-paper"
                    @click="open = true"
                    aria-label="Open menu"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                </button>
                <span class="text-sm font-semibold tracking-tight">{{ $branding->company_name }}</span>
            </header>

            <main id="content" class="flex-1 px-4 py-6 lg:px-6 lg:py-6">
                @if (session('status'))
                    <p class="mb-4 rounded-[4px] border border-line bg-surface px-3 py-2 text-sm">{{ session('status') }}</p>
                @endif
                <div class="mx-auto w-full max-w-6xl">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>
</div>

@livewireScripts
</body>
</html>
