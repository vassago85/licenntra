@props([
    'href' => '#',
    'active' => false,
    'count' => null,
    'countTone' => 'mono',
])

@php
    $countClasses = match ($countTone) {
        'warning' => 'bg-[#FAEFD4] text-[#6E4B00] px-1.5 py-px rounded-full',
        'danger' => 'bg-[#FBE5E2] text-[#9E2419] px-1.5 py-px rounded-full',
        'success' => 'bg-[#E2F1E6] text-[#1C6535] px-1.5 py-px rounded-full',
        default => 'text-muted',
    };

    $itemClasses = $active
        ? 'flex min-h-10 items-center justify-between gap-3 rounded-md bg-[#EEF1FA] px-2.5 text-sm font-semibold text-[color:var(--brand)]'
        : 'flex min-h-10 items-center justify-between gap-3 rounded-md px-2.5 text-sm text-ink hover:bg-paper';
@endphp

<a href="{{ $href }}" @class([$itemClasses])>
    <span class="truncate">{{ $slot }}</span>
    @if ($count !== null)
        <span @class(['font-mono text-xs', $countClasses])>{{ $count }}</span>
    @endif
</a>
