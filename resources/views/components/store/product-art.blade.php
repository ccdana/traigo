@props(['category' => null, 'name' => '', 'image' => null, 'alt' => ''])

{{-- La foto del producto o, mientras no haya, una ilustración de su categoría. --}}
@php
    $background = match ($category) {
        'tomatodos' => 'bg-art-sky',
        'macetas' => 'bg-art-mint',
        default => 'bg-art-lilac',
    };
    $kind = match (true) {
        $category === 'tomatodos' => 'bottle',
        $category === 'macetas' => 'pot',
        str_starts_with($name, 'Set') => 'blocks',
        default => 'car',
    };
@endphp

<div {{ $attributes->merge(['class' => "product-art flex aspect-4/3 items-center justify-center overflow-hidden {$background}"]) }}>
    @if ($image)
        <img src="{{ $image }}" alt="{{ $alt }}" loading="lazy" class="size-full object-cover">
    @else
        <svg viewBox="0 0 96 96" class="h-3/5 w-3/5" aria-hidden="true" stroke="#150b3d" stroke-width="3" stroke-linejoin="round">
            @if ($kind === 'bottle')
                <rect x="40" y="8" width="16" height="12" rx="2" fill="#150b3d"/>
                <path d="M38 20h20v8c6 4 8 8 8 14v40a6 6 0 0 1-6 6H36a6 6 0 0 1-6-6V42c0-6 2-10 8-14z" fill="#5b12f5"/>
                <rect x="38" y="50" width="20" height="16" fill="#fff"/>
            @elseif ($kind === 'pot')
                <path d="M48 46C46 30 34 24 24 26c2 12 12 20 24 20zm0 0c2-18 14-26 26-24-1 14-12 24-26 24z" fill="#12c4e6"/>
                <rect x="22" y="46" width="52" height="12" rx="2" fill="#5b12f5"/>
                <path d="M28 58h40l-6 30H34z" fill="#d9c8ff"/>
            @elseif ($kind === 'blocks')
                <rect x="14" y="52" width="32" height="32" fill="#5b12f5"/>
                <rect x="50" y="52" width="32" height="32" fill="#12c4e6"/>
                <rect x="32" y="16" width="32" height="32" fill="#8f66ff"/>
            @else
                <path d="M12 62v-12l12-4 10-18h28l12 18 10 4v12z" fill="#5b12f5"/>
                <path d="M36 34h11v12H28zm17 0h7l9 12H53z" fill="#12c4e6"/>
                <circle cx="30" cy="64" r="10" fill="#fff"/>
                <circle cx="68" cy="64" r="10" fill="#fff"/>
            @endif
        </svg>
    @endif
</div>
