// Las mejoras visuales no interceptan compras ni sustituyen formularios.
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const running = new Set();
function animate(element, frames, duration = 220) {
    if (!element || reducedMotion.matches || !element.animate) return;
    const animation = element.animate(frames, { duration, easing: 'cubic-bezier(.2,.8,.2,1)' });
    running.add(animation);
    animation.finished.catch(() => {}).finally(() => running.delete(animation));
}
reducedMotion.addEventListener('change', () => {
    if (reducedMotion.matches) running.forEach(animation => animation.cancel());
});

if (document.querySelector('[data-import-hero]')) import('./store-scenes.js');

if ('IntersectionObserver' in window && !reducedMotion.matches) {
    const reveals = document.querySelectorAll('[data-scroll-reveal], [data-holiday-reveal]');
    const observer = new IntersectionObserver(entries => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            observer.unobserve(entry.target);
            if (reducedMotion.matches) continue;
            entry.target.setAttribute('data-arrived', '');
            if (entry.target.hasAttribute('data-scroll-reveal')) {
                animate(entry.target, [{ opacity: .65, transform: 'translateY(12px)' }, { opacity: 1, transform: 'none' }], 480);
            }
        }
    }, { threshold: .55, rootMargin: '0px 0px -5% 0px' });
    reveals.forEach(element => observer.observe(element));
}

const toggle = document.querySelector('[data-nav-toggle]');
if (toggle) {
    const navigation = document.getElementById(toggle.getAttribute('aria-controls'));
    const close = () => {
        navigation.removeAttribute('data-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.querySelector('span').textContent = '+';
    };
    navigation.setAttribute('data-collapsible', '');
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        if (toggle.getAttribute('aria-expanded') === 'true') close();
        else {
            navigation.setAttribute('data-open', '');
            toggle.setAttribute('aria-expanded', 'true');
            toggle.querySelector('span').textContent = '−';
            animate(navigation, [{opacity: .3, transform: 'translateY(-6px)'}, {opacity: 1, transform: 'none'}]);
        }
    });
    toggle.closest('header').addEventListener('keydown', event => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true' && toggle.getClientRects().length > 0) {
            close(); toggle.focus();
        }
    });
}

// El cambio de cantidad queda visible sin alterar el evento de compra.
document.querySelectorAll('[data-stepper]').forEach(stepper => {
    stepper.addEventListener('click', event => {
        if (event.target.closest('[data-step]')) animate(stepper.querySelector('input'), [
            {transform: 'scale(1)'}, {transform: 'scale(1.12)'}, {transform: 'scale(1)'},
        ]);
    });
});
const cart = document.querySelector('[data-cart-count]');
if (cart) {
    // El almacenamiento puede estar deshabilitado: la tienda sigue funcionando.
    try {
        const previous = sessionStorage.getItem('traigo-cart-count');
        if (previous !== null && Number(cart.dataset.cartCount) > Number(previous)) {
            animate(cart, [{transform: 'scale(1)'}, {transform: 'scale(1.06)'}, {transform: 'scale(1)'}], 320);
        }
        sessionStorage.setItem('traigo-cart-count', cart.dataset.cartCount);
    } catch { /* Sin almacenamiento, solo se omite el énfasis visual. */ }
}
