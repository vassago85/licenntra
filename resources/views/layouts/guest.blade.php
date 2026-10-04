<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($branding = $branding ?? \App\Models\BrandingSetting::current())
    <title>{{ $branding->company_name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --brand: {{ $branding->primary_colour ?? '#1F47B8' }}; }</style>
</head>
<body class="min-h-screen bg-paper text-ink antialiased">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
        <p class="mb-6 text-sm font-semibold">{{ $branding->company_name }}</p>
        @yield('content')
    </main>
</body>
</html>
