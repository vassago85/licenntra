<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($branding = $branding ?? \App\Models\BrandingSetting::current())
    <title>{{ $title ?? $branding->company_name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @php($brand = $branding->primary_colour ?? '#1F47B8')
    <style>:root { --brand: {{ $brand }}; } [x-cloak] { display: none !important; }</style>
</head>
<body class="min-h-screen bg-paper text-ink antialiased">
<a class="sr-only focus:not-sr-only focus:absolute focus:left-3 focus:top-3 focus:z-50 focus:bg-ink focus:px-3 focus:py-2 focus:text-white" href="#content">Skip to content</a>

<div x-data="{ open: false }" class="min-h-screen lg:grid lg:grid-cols-[240px_1fr]">
    <x-portal.sidebar />

    <div class="flex min-h-screen flex-col">
        <header class="sticky top-0 z-10 flex h-14 items-center gap-3 border-b border-line bg-white px-4 lg:hidden">
            <button
                type="button"
                class="rounded-md p-1 text-ink hover:bg-paper"
                @click="open = true"
                aria-label="Open menu"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            </button>
            <span class="text-sm font-semibold tracking-tight">{{ $branding->company_name }}</span>
        </header>

        <main id="content" class="flex-1 px-4 py-6 lg:px-8 lg:py-8">
            @if (session('status'))
                <p class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm">{{ session('status') }}</p>
            @endif
            <div class="mx-auto w-full max-w-6xl">
                {{ $slot }}
            </div>
        </main>
    </div>
</div>

@livewireScripts
</body>
</html>
