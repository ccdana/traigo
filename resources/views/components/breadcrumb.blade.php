@props(['trail' => [], 'current'])

<nav aria-label="Ruta" {{ $attributes->merge(['class' => 'font-mono text-[11px] text-muted']) }}>
    <ol class="flex flex-wrap gap-2">
        @foreach ($trail as $label => $url)
            <li><a href="{{ $url }}" class="hover:text-brand">{{ $label }}</a></li>
            <li aria-hidden="true">/</li>
        @endforeach
        <li class="font-semibold text-brand" aria-current="page">{{ $current }}</li>
    </ol>
</nav>
