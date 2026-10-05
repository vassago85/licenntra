@auth
    @php
        $branding = \App\Models\BrandingSetting::current();
        $brand = $branding->primary_colour ?? '#146d61';
    @endphp

    {{-- Brand tokens for the portal sidebar inside the Filament panel. --}}
    <style>
        :root {
            --brand: {{ $brand }};
            --brand-soft: #eaf4f0;
        }
        [x-cloak] { display: none !important; }
    </style>

    {{-- Alpine state container so the mobile sidebar toggle works inside Filament too. --}}
    <div x-data="{ open: false }" class="licentra-shell">
        <x-portal.sidebar :branding="$branding" />

        {{-- Mobile hamburger — Filament has no equivalent exposed once we hide its topbar. --}}
        <button
            type="button"
            class="licentra-shell-menu-btn"
            @click="open = true"
            aria-label="Open menu"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
        </button>
    </div>
@endauth
