@php
    $branding = \App\Models\BrandingSetting::current();
    $roleName = auth()->check() ? (string) (auth()->user()->getRoleNames()->first() ?? 'user') : null;
    $roleLabel = $roleName === null ? null : (\App\Livewire\Portal\Admin\Users::ROLE_LABELS[$roleName] ?? ucfirst($roleName));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Not available · {{ $branding->company_name }}</title>
    @vite(['resources/css/app.css'])
    <style>:root { --brand: {{ $branding->primary_colour ?? '#146d61' }}; }</style>
</head>
<body class="min-h-screen bg-paper text-ink antialiased">
<main class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-md rounded-md border border-line bg-white p-6 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted">403 · Not available</p>
        <h1 class="mt-1 text-xl font-semibold">You don't have access to this page</h1>
        <p class="mt-2 text-sm text-muted">
            @auth
                Your role ({{ $roleLabel }}) can't open this screen. If you believe this is wrong, ask your administrator.
            @else
                You need to be signed in to see this.
            @endauth
        </p>
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
