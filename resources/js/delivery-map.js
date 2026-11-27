import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

export function deliveryMap(root) {
    const canvas = root.querySelector('[data-map-canvas]');
    const status = root.querySelector('[data-map-status]');
    const lat = root.querySelector('[name="latitude"]');
    const lng = root.querySelector('[name="longitude"]');
    const address = root.closest('[data-delivery-address]').querySelector('[name="address"]');
    const results = root.querySelector('[data-map-results]');
    const search = root.querySelector('[data-map-search]');
    const locate = root.querySelector('[data-map-locate]');
    const hasPoint = () => lat.value !== '' && lng.value !== '' && lat.validity.valid && lng.validity.valid;
    const start = hasPoint() ? [Number(lat.value), Number(lng.value)] : [Number(root.dataset.lat), Number(root.dataset.lng)];
    const map = L.map(canvas, { scrollWheelZoom: false }).setView(start, hasPoint() ? 17 : Number(root.dataset.zoom));
    const attribution = document.createElement('a');
    attribution.href = root.dataset.attributionUrl;
    attribution.textContent = root.dataset.attribution;
    attribution.target = '_blank';
    attribution.rel = 'noopener noreferrer';
    L.tileLayer(root.dataset.tiles, { maxZoom: 19, attribution: attribution.outerHTML }).addTo(map)
        .once('tileerror', () => { status.textContent = 'No se pudo cargar una parte del mapa. Revisa tu conexión o ingresa las coordenadas.'; });
    const icon = L.divIcon({
        className: 'delivery-marker', iconSize: [32, 42], iconAnchor: [16, 42],
        html: '<svg viewBox="0 0 32 42" aria-hidden="true"><path d="M16 41C13 36 1 24 1 16a15 15 0 0 1 30 0c0 8-12 20-15 25Z" fill="#5b12f5" stroke="white" stroke-width="2"/><circle cx="16" cy="16" r="5" fill="white"/></svg>',
    });
    let marker;
    const choose = (point, pan = false) => {
        point = point.wrap();
        lat.value = point.lat.toFixed(7);
        lng.value = point.lng.toFixed(7);
        if (!marker) {
            marker = L.marker(point, { icon, draggable: true, alt: 'Punto de entrega; arrastra para ajustar' }).addTo(map);
            marker.on('dragend', () => choose(marker.getLatLng()));
        } else marker.setLatLng(point);
        if (pan) map.setView(point, 17);
        status.textContent = 'Punto de entrega seleccionado. Puedes mover el marcador para ajustarlo.';
    };
    map.on('click', (event) => choose(event.latlng));
    if (hasPoint()) choose(L.latLng(start));
    root.querySelector('[data-map-center]').addEventListener('click', () => choose(map.getCenter()));
    for (const input of [lat, lng]) input.addEventListener('change', () => {
        if (hasPoint()) choose(L.latLng(Number(lat.value), Number(lng.value)), true);
    });
    address.addEventListener('input', () => {
        lat.value = '';
        lng.value = '';
        if (marker) { map.removeLayer(marker); marker = null; }
        results.hidden = true;
        status.textContent = 'Cambiaste la dirección. Marca o busca nuevamente el punto de entrega.';
    });
    search.addEventListener('click', async () => {
        const query = address.value.trim();
        if (query.length < 3) { status.textContent = 'Escribe al menos tres caracteres de la calle o zona.'; address.focus(); return; }
        search.disabled = true;
        results.hidden = true;
        status.textContent = 'Buscando dirección…';
        try {
            const url = new URL(root.dataset.searchUrl, window.location.origin);
            url.searchParams.set('q', query);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: AbortSignal.timeout(12000) });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message || 'No pudimos buscar. Marca el punto directamente en el mapa.');
            // Una respuesta antigua no debe reemplazar lo que la persona acaba de escribir.
            if (address.value.trim() !== query) return;
            results.replaceChildren();
            for (const point of body.data) {
                const li = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'block w-full cursor-pointer p-3 text-left text-sm hover:bg-soft';
                button.textContent = point.label;
                button.addEventListener('click', () => {
                    address.value = point.label.slice(0, 300);
                    choose(L.latLng(point.latitude, point.longitude), true);
                    results.hidden = true;
                });
                li.append(button);
                results.append(li);
            }
            results.hidden = body.data.length === 0;
            status.textContent = body.data.length ? 'Elige un resultado y ajusta el marcador hasta tu puerta.' : 'No encontramos esa dirección. Marca el punto directamente en el mapa.';
        } catch (error) { status.textContent = error.name === 'TimeoutError' ? 'La búsqueda tardó demasiado. Marca el punto en el mapa.' : error.message; }
        finally { search.disabled = false; }
    });
    locate.addEventListener('click', () => {
        if (!navigator.geolocation) { status.textContent = 'Este navegador no permite obtener tu ubicación. Marca el punto en el mapa.'; return; }
        locate.disabled = true;
        status.textContent = 'Obteniendo tu ubicación…';
        navigator.geolocation.getCurrentPosition((position) => {
            choose(L.latLng(position.coords.latitude, position.coords.longitude), true);
            status.textContent = 'Ubicación aproximada seleccionada. Comprueba que el marcador esté en tu puerta.';
            locate.disabled = false;
        }, () => {
            status.textContent = 'No pudimos obtener tu ubicación. Puedes buscar la dirección o marcar el punto.';
            locate.disabled = false;
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
    });
    return () => map.invalidateSize();
}
