/*
 * Mejoras sobre páginas que ya funcionan sin JavaScript: la cuenta regresiva,
 * los botones de cantidad y el cambio de foto.
 */

function countdown(element) {
    const target = new Date(element.dataset.countdown).getTime();
    const parts = element.querySelectorAll('[data-unit]');

    const tick = () => {
        let seconds = Math.max(0, Math.floor((target - Date.now()) / 1000));
        const values = {
            days: Math.floor(seconds / 86400),
            hours: Math.floor((seconds % 86400) / 3600),
            minutes: Math.floor((seconds % 3600) / 60),
            seconds: seconds % 60,
        };

        parts.forEach((part) => {
            part.textContent = String(values[part.dataset.unit]).padStart(2, '0');
        });
    };

    tick();
    setInterval(tick, 1000);
}

function stepper(element) {
    const input = element.querySelector('input[type="number"]');

    element.querySelectorAll('[data-step]').forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', () => {
            const min = Number(input.min || 1);
            const max = Number(input.max || 999);
            const next = Number(input.value || min) + Number(button.dataset.step);
            input.value = String(Math.min(max, Math.max(min, next)));
        });
    });
}

function gallery(element) {
    const main = element.querySelector('[data-gallery-main]');

    element.querySelectorAll('[data-gallery-thumb]').forEach((button) => {
        button.addEventListener('click', () => {
            main.src = button.dataset.galleryThumb;
        });
    });
}

// La dirección solo hace falta con delivery. Sin JavaScript queda visible.
function delivery(element) {
    const address = element.querySelector('[data-delivery-address]');
    const radios = element.querySelectorAll('input[name="delivery_type"]');
    const mapRoot = element.querySelector('[data-delivery-map]');
    let refreshMap;
    let loading = false;

    const update = () => {
        const isDelivery = element.querySelector('input[name="delivery_type"]:checked')?.value === 'delivery';
        address.hidden = !isDelivery;
        address.querySelector('[name="address"]').required = isDelivery;
        address.querySelectorAll('input, button').forEach((input) => { input.disabled = !isDelivery; });
        if (isDelivery && mapRoot) {
            if (refreshMap) requestAnimationFrame(refreshMap);
            else if (!loading) {
                loading = true;
                import('./delivery-map.js').then(({ deliveryMap }) => {
                    refreshMap = deliveryMap(mapRoot);
                    requestAnimationFrame(refreshMap);
                }).catch(() => {
                    mapRoot.querySelector('[data-map-status]').textContent = 'No se pudo cargar el mapa. Recarga la página o ingresa las coordenadas.';
                    mapRoot.querySelector('[data-map-coordinates]').open = true;
                    loading = false;
                });
            }
        }
    };

    radios.forEach((radio) => radio.addEventListener('change', update));
    update();
    element.closest('form').addEventListener('submit', (event) => {
        if (address.hidden || !mapRoot) return;
        const missing = ['latitude', 'longitude'].some((name) => !mapRoot.querySelector(`[name="${name}"]`).value);
        if (missing) {
            event.preventDefault();
            mapRoot.querySelector('[data-map-status]').textContent = 'Marca el punto de entrega antes de confirmar el pedido.';
            mapRoot.scrollIntoView({ block: 'center', behavior: 'auto' });
        }
    });
}

// Evita el doble clic en envíos que apartan stock o suben archivos. El
// servidor igual no los duplica.
function once(form) {
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        const button = form.querySelector('button[type="submit"]');
        setTimeout(() => (button.disabled = true));
    });
    window.addEventListener('pageshow', () => (form.querySelector('button[type="submit"]').disabled = false));
}

// Filtra una lista larga mientras se escribe, sin tildes ni mayúsculas.
function filter(input) {
    const fold = (text) => text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
    const items = document.querySelectorAll(`${input.dataset.filter} > li`);

    input.addEventListener('input', () => {
        const term = fold(input.value.trim());
        items.forEach((item) => (item.hidden = !fold(item.textContent).includes(term)));
    });
    // Enter no envía el formulario desde el buscador.
    input.addEventListener('keydown', (event) => event.key === 'Enter' && event.preventDefault());
}

document.querySelectorAll('[data-filter]').forEach(filter);
document.querySelectorAll('[data-delivery]').forEach(delivery);
document.querySelectorAll('form[data-once]').forEach(once);
document.querySelectorAll('[data-countdown]').forEach(countdown);
document.querySelectorAll('[data-stepper]').forEach(stepper);
document.querySelectorAll('[data-gallery]').forEach(gallery);

// Los filtros de orden se aplican al elegir; sin JavaScript queda el botón.
document.querySelectorAll('[data-autosubmit]').forEach((select) => {
    select.form.querySelector('[data-autosubmit-button]')?.setAttribute('hidden', '');
    select.addEventListener('change', () => select.form.submit());
});
