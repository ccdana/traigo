@props(['tone' => 'color'])

{{-- Provisional: se reemplaza por el archivo oficial del logo cuando esté disponible. --}}
<a href="{{ route('store.home') }}" {{ $attributes->merge(['class' => 'inline-block']) }} aria-label="Traigo, ir al inicio">
    <span @class([
        'font-display text-3xl leading-none font-bold tracking-wide',
        'bg-linear-to-r from-brand to-cyan bg-clip-text text-transparent' => $tone === 'color',
        'text-white' => $tone === 'light',
    ])>TRAIGO</span>
</a>
