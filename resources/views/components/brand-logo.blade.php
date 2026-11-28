@props(['tone' => 'color'])

{{-- Archivos oficiales exportados de la referencia de marca; alojados localmente. --}}
<a href="{{ route('store.home') }}" {{ $attributes->merge(['class' => 'inline-block']) }} aria-label="Traigo, ir al inicio">
    <img src="{{ asset($tone === 'light' ? 'brand/traigo-white.png' : 'brand/traigo-logo.png') }}" alt="TRAIGO · Accesorios y Tecnología" width="683" height="186" class="brand-image" decoding="async">
</a>
