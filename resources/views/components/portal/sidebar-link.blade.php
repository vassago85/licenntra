@props([
    'href' => '#',
    'active' => false,
    'count' => null,
    'countTone' => 'mono',
    'icon' => null,
])

@php
    $countClasses = match ($countTone) {
        'warning' => 'bg-[#FAEFD4] text-[#6E4B00] px-1.5 py-px rounded-full',
        'danger' => 'bg-[#FBE5E2] text-[#9E2419] px-1.5 py-px rounded-full',
        'success' => 'bg-[#E2F1E6] text-[#1C6535] px-1.5 py-px rounded-full',
        default => 'text-muted',
    };

    $itemClasses = $active
        ? 'flex min-h-9 items-center justify-between gap-2.5 rounded-[4px] bg-[color:var(--brand-soft)] px-2 py-2 text-[12px] font-semibold text-[color:var(--brand)]'
        : 'flex min-h-9 items-center justify-between gap-2.5 rounded-[4px] px-2 py-2 text-[12px] text-ink hover:bg-paper';
@endphp

<a href="{{ $href }}" @class([$itemClasses])>
    <span class="flex min-w-0 items-center gap-2.5">
        @if ($icon)
            <span @class([
                'flex h-4 w-4 shrink-0 items-center justify-center',
                'text-[color:var(--brand)]' => $active,
                'text-muted' => ! $active,
            ])>{!! $icon !!}</span>
        @endif
        <span class="truncate">{{ $slot }}</span>
    </span>
    @if ($count !== null)
        <span @class(['tabular-nums text-[11px]', $countClasses])>{{ $count }}</span>
    @endif
</a>
