<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $branding = $branding ?? \App\Models\BrandingSetting::current();
    @endphp
    <title>{{ $branding->company_name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --brand: {{ $branding->primary_colour ?? '#146d61' }}; --brand-soft: #eaf4f0; }</style>
</head>
<body class="min-h-screen bg-paper text-ink antialiased">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
        <p class="mb-6 text-sm font-semibold">{{ $branding->company_name }}</p>
        @yield('content')
    </main>
</body>
</html>
