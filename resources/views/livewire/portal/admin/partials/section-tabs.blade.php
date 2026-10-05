{{-- Expects $tabs: array<string, string> of route name => tab label. --}}
<nav class="mb-4 flex flex-wrap gap-1 border-b border-line" aria-label="Section">
    @foreach ($tabs as $routeName => $label)
        <a
            href="{{ route($routeName) }}"
            class="-mb-px border-b-2 px-3 py-2 text-sm {{ request()->routeIs($routeName) ? 'border-[var(--brand)] font-semibold text-ink' : 'border-transparent text-muted hover:text-ink' }}"
        >{{ $label }}</a>
    @endforeach
</nav>
