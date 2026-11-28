@props(['lastLocation' => null])

<div class="mt-4 space-y-3" data-delivery-map
     data-tiles="{{ config('delivery-map.tiles_url') }}"
     data-attribution="{{ config('delivery-map.attribution') }}"
     data-attribution-url="{{ config('delivery-map.attribution_url') }}"
     data-lat="{{ config('delivery-map.latitude') }}" data-lng="{{ config('delivery-map.longitude') }}"
     data-zoom="{{ config('delivery-map.zoom') }}" data-search-url="{{ route('store.delivery.search') }}">
    <p class="text-sm font-medium">Marca dónde entregamos tu pedido</p>
    <p class="text-xs text-muted">Busca una calle o zona, toca el mapa y arrastra el marcador hasta tu puerta.</p>
    <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-soft py-2" data-map-search>Buscar dirección</button>
        <button type="button" class="btn-soft py-2" data-map-locate>Usar mi ubicación</button>
        <button type="button" class="btn-soft py-2" data-map-center>Marcar centro del mapa</button>
    </div>
    <ul data-map-results class="divide-y divide-line border border-line" aria-label="Direcciones encontradas" hidden></ul>
    <div data-map-canvas class="delivery-map" role="region" aria-label="Mapa del punto de entrega"></div>
    <p data-map-status role="status" tabindex="-1" class="text-sm text-muted">Elige el punto de entrega. El mapa comienza en Cochabamba.</p>
    <details data-map-coordinates class="text-sm text-muted" @if ($errors->hasAny(['latitude', 'longitude'])) open @endif>
        <summary class="cursor-pointer">Ver o ingresar coordenadas</summary>
        <div class="mt-2 grid grid-cols-2 gap-3">
            <div><label for="latitude">Latitud</label><input class="field" id="latitude" name="latitude" type="number" step="any" min="-90" max="90" value="{{ old('latitude', $lastLocation?->latitude) }}"></div>
            <div><label for="longitude">Longitud</label><input class="field" id="longitude" name="longitude" type="number" step="any" min="-180" max="180" value="{{ old('longitude', $lastLocation?->longitude) }}"></div>
        </div>
    </details>
    <noscript><p class="text-sm">Activa JavaScript para elegir el punto en el mapa, o ingresa sus coordenadas en la opción anterior.</p></noscript>
    <p class="text-xs text-muted">La búsqueda envía solo la dirección escrita al servicio de mapas. La ubicación del dispositivo se solicita únicamente si eliges usarla.</p>
</div>
