@php
    $branding = \App\Models\BrandingSetting::current();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Not found · {{ $branding->company_name }}</title>
    @vite(['resources/css/app.css'])
    <style>:root { --brand: {{ $branding->primary_colour ?? '#146d61' }}; }</style>
</head>
<body class="min-h-screen bg-paper text-ink antialiased">
<main class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-md rounded-md border border-line bg-white p-6 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted">404 · Not found</p>
        <h1 class="mt-1 text-xl font-semibold">We couldn't find that page</h1>
        <p class="mt-2 text-sm text-muted">The link may be stale, the record may have been removed, or the URL could be mistyped.</p>
        <div class="mt-5 flex flex-wrap items-center gap-2">
            @auth
                <a href="{{ route('dashboard') }}" class="h-9 rounded-md px-3 text-sm font-semibold leading-9 text-white" style="background: var(--brand)">Back to dashboard</a>
                <form method="POST" action="{{ url('/logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="h-9 rounded-md border border-line bg-white px-3 text-sm">Sign out</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="h-9 rounded-md px-3 text-sm font-semibold leading-9 text-white" style="background: var(--brand)">Sign in</a>
            @endauth
        </div>
    </div>
</main>
</body>
</html>
