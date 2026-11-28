import { mountImportGlobe } from './import-globe.js';

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const clamp = value => Math.min(1, Math.max(0, value));
const hero = document.querySelector('[data-import-hero]');
const tunnel = document.querySelector('[data-catalog-tunnel]');
const delivery = document.querySelector('[data-delivery-scene]');

// Each scene measures and renders at most once per paint and only near the viewport.
function scrollScene(element, render, margin = '100px 0px') {
    let frame = 0;
    let visible = false;
    function schedule() {
        if (!visible || frame) return;
        frame = requestAnimationFrame(() => {
            frame = 0;
            if (visible) render();
        });
    }
    new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting;
        schedule();
    }, { rootMargin: margin }).observe(element);
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    reducedMotion.addEventListener('change', schedule);
    return schedule;
}

function mountTunnel(section) {
    const runway = section.querySelector('[data-tunnel-runway]');
    const stage = section.querySelector('[data-tunnel-stage]');
    const frames = [...section.querySelectorAll('[data-tunnel-frame]')];
    const controls = section.querySelector('[data-tunnel-controls]');
    const buttons = [...controls.querySelectorAll('button')];
    const position = controls.querySelector('[data-tunnel-position]');
    const announcement = stage.querySelector('[data-tunnel-announcement]');
    const limitedViewport = window.matchMedia('(max-width: 699px), (max-height: 570px)');
    const last = frames.length - 1;
    let current = 0;
    let mobileStop = 0;
    if (last < 1) return;

    function render() {
        if (reducedMotion.matches) return;
        const fixed = limitedViewport.matches;
        const bounds = runway.getBoundingClientRect();
        const travel = Math.max(1, bounds.height - stage.offsetHeight);
        const stickyTop = fixed ? 0 : Number.parseFloat(getComputedStyle(stage).top);
        const progress = fixed ? mobileStop : clamp((stickyTop - bounds.top) / travel) * last;
        for (const [index, panel] of frames.entries()) {
            const relative = index - progress;
            const distance = Math.abs(relative);
            panel.style.setProperty('--frame-x', `${Math.tanh(relative * 1.2) * 120}%`);
            panel.style.setProperty('--frame-y', `${Math.min(44, distance * 17)}px`);
            panel.style.setProperty('--frame-z', `${-distance * 205}px`);
            panel.style.setProperty('--frame-turn', `${Math.tanh(relative) * -42}deg`);
            panel.style.setProperty('--frame-opacity', Math.max(.14, 1 - distance * .4));
            panel.style.setProperty('--object-lift', clamp(1 - distance * 1.35).toFixed(3));
            panel.style.zIndex = 1000 - Math.min(1000, Math.round(distance * 100));
        }
        current = Math.round(progress);
        position.textContent = `${current + 1} / ${frames.length}`;
        buttons[0].setAttribute('aria-disabled', String(current === 0));
        buttons[1].setAttribute('aria-disabled', String(current === last));
    }

    function syncMotion() {
        section.toggleAttribute('data-cinematic', !reducedMotion.matches);
        controls.hidden = reducedMotion.matches;
        if (!reducedMotion.matches) render();
    }

    buttons.forEach(button => button.addEventListener('click', () => {
        const next = current + Number(button.dataset.tunnelStep);
        if (next < 0 || next > last) return;
        announcement.textContent = `${frames[next].dataset.tunnelFrame}, ${next + 1} de ${frames.length}`;
        if (limitedViewport.matches) {
            mobileStop = next;
            render();
            return;
        }
        const travel = runway.offsetHeight - stage.offsetHeight;
        const stickyTop = Number.parseFloat(getComputedStyle(stage).top);
        window.scrollTo({ top: Math.round(window.scrollY + runway.getBoundingClientRect().top - stickyTop + next / last * travel), behavior: 'smooth' });
    }));
    scrollScene(runway, render);
    limitedViewport.addEventListener('change', render);
    reducedMotion.addEventListener('change', syncMotion);
    syncMotion();
}

const deliveryRoute = [[12, 74], [34, 74], [34, 45], [65, 45], [65, 27], [82, 27]];
const deliveryLegs = deliveryRoute.slice(1).map(([x, y], index) => {
    const [startX, startY] = deliveryRoute[index];
    const length = Math.hypot((x - startX) * 1.6, y - startY);
    return { startX, startY, x, y, length, angle: Math.atan2(y - startY, x - startX) * 180 / Math.PI };
});
const deliveryDistance = deliveryLegs.reduce((total, leg) => total + leg.length, 0);

function mountDelivery(scene) {
    const map = scene.querySelector('[data-delivery-map-art]');
    const runner = scene.querySelector('[data-delivery-runner]');
    const parcel = scene.querySelector('[data-delivery-parcel]');
    const label = scene.querySelector('[data-delivery-state]');
    const announcement = scene.querySelector('[data-delivery-announcement]');
    const replay = scene.querySelector('[data-delivery-replay]');
    let replaying = false;
    let replayFrame = 0;
    let finalProgress = null;

    function show(progress) {
        let remaining = clamp(progress / .82) * deliveryDistance;
        for (const [index, leg] of deliveryLegs.entries()) {
            if (remaining > leg.length && index < deliveryLegs.length - 1) {
                remaining -= leg.length;
                continue;
            }
            const t = clamp(remaining / leg.length);
            runner.style.setProperty('--van-x', `${leg.startX + (leg.x - leg.startX) * t}%`);
            runner.style.setProperty('--van-y', `${leg.startY + (leg.y - leg.startY) * t}%`);
            runner.style.setProperty('--van-angle', `${leg.angle}deg`);
            break;
        }
        const transfer = clamp((progress - .82) / .16);
        parcel.style.setProperty('--handoff', transfer);
        const state = progress >= .98 ? 'Paquete entregado' : progress >= .82 ? 'Dejando el paquete' : progress > .04 ? 'En camino' : 'Sale de Traigo';
        if (label.textContent !== state) label.textContent = state;
        map.toggleAttribute('data-handoff', progress >= .82);
        map.toggleAttribute('data-delivered', progress >= .98);
    }

    function render() {
        if (replaying || reducedMotion.matches) return;
        const bounds = map.getBoundingClientRect();
        const progress = clamp((window.innerHeight * .84 - bounds.top) / Math.min(bounds.height * .82, window.innerHeight * .54));
        // Keep the replay finale while its scene remains at the same scroll position.
        if (finalProgress !== null && Math.abs(progress - finalProgress) < .04) return;
        finalProgress = null;
        show(progress);
    }

    function syncMotion() {
        replay.hidden = reducedMotion.matches;
        scene.toggleAttribute('data-animated-delivery', !reducedMotion.matches);
        if (reducedMotion.matches) {
            cancelAnimationFrame(replayFrame);
            replaying = false;
            replay.removeAttribute('aria-disabled');
            show(1);
        } else {
            render();
        }
    }

    replay.addEventListener('click', () => {
        if (replaying) return;
        replaying = true;
        replay.setAttribute('aria-disabled', 'true');
        announcement.textContent = '';
        const start = performance.now();
        const play = now => {
            const progress = clamp((now - start) / 2600);
            show(progress);
            if (progress < 1) {
                replayFrame = requestAnimationFrame(play);
            } else {
                replaying = false;
                const bounds = map.getBoundingClientRect();
                finalProgress = clamp((window.innerHeight * .84 - bounds.top) / Math.min(bounds.height * .82, window.innerHeight * .54));
                replay.removeAttribute('aria-disabled');
                announcement.textContent = 'Simulación terminada. El paquete llegó a la puerta.';
            }
        };
        replayFrame = requestAnimationFrame(play);
    });
    scrollScene(map, render);
    reducedMotion.addEventListener('change', syncMotion);
    syncMotion();
}

if ('IntersectionObserver' in window) {
    if (hero && 'ResizeObserver' in window) mountImportGlobe(hero, reducedMotion);
    if (tunnel) mountTunnel(tunnel);
    if (delivery) mountDelivery(delivery);
}
