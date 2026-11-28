export function categoryCarousel(carousel, reducedMotion) {
    const stage = carousel.querySelector('[data-cargo-stage]');
    const slides = Array.from(stage.querySelectorAll('[data-cargo-slide]'));
    if (slides.length < 2) return;

    const number = carousel.querySelector('[data-cargo-current]');
    const status = carousel.querySelector('[data-cargo-status]');
    const links = slides.map(slide => slide.querySelector('a'));
    let current = 0;
    let gesture;
    let suppressClick = false;
    let landingObserver;
    const wrapIndex = index => (index + slides.length) % slides.length;

    function show(index, announce = true) {
        const focusLink = document.activeElement === links[current];
        current = wrapIndex(index);
        slides.forEach((slide, position) => {
            const active = position === current;
            if (active) slide.dataset.position = 'current';
            else if (position === wrapIndex(current + 1)) slide.dataset.position = 'next';
            else if (position === wrapIndex(current - 1)) slide.dataset.position = 'previous';
            else delete slide.dataset.position;

            links[position].inert = !active;
            links[position].tabIndex = active ? 0 : -1;
            if (active) slide.removeAttribute('aria-hidden');
            else slide.setAttribute('aria-hidden', 'true');
        });
        number.textContent = String(current + 1).padStart(2, '0');
        if (announce) status.textContent = `${slides[current].dataset.categoryName}, categoría ${current + 1} de ${slides.length}`;
        if (focusLink) links[current].focus({ preventScroll: true });
    }

    function advance(step) {
        stopLanding();
        show(current + step);
    }

    function stopLanding() {
        landingObserver?.disconnect();
        carousel.removeAttribute('data-landing');
    }

    show(0, false);
    carousel.setAttribute('data-enhanced', '');
    carousel.querySelector('[data-cargo-controls]').hidden = false;
    if (!reducedMotion.matches && 'IntersectionObserver' in window) {
        landingObserver = new IntersectionObserver(entries => {
            if (!entries.some(entry => entry.isIntersecting)) return;
            landingObserver.disconnect();
            if (!reducedMotion.matches) carousel.setAttribute('data-landing', '');
        }, { threshold: .35 });
        landingObserver.observe(stage);
    }
    carousel.addEventListener('animationend', event => {
        if (event.animationName === 'cargo-land') stopLanding();
    });
    reducedMotion.addEventListener('change', () => {
        if (reducedMotion.matches) stopLanding();
    });

    carousel.querySelectorAll('[data-cargo-step]').forEach(button => {
        button.addEventListener('click', () => advance(Number(button.dataset.cargoStep)));
    });
    carousel.addEventListener('keydown', event => {
        if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        advance(event.key === 'ArrowLeft' ? -1 : 1);
    });

    // Un toque abre el enlace. Un gesto horizontal gira las cajas.
    stage.addEventListener('pointerdown', event => {
        if (!event.isPrimary || event.button !== 0) return;
        suppressClick = false;
        gesture = { id: event.pointerId, x: event.clientX, y: event.clientY, dragging: false };
    });
    stage.addEventListener('pointermove', event => {
        if (gesture?.id !== event.pointerId) return;
        const dx = event.clientX - gesture.x;
        const dy = event.clientY - gesture.y;
        if (!gesture.dragging) {
            if (Math.abs(dy) > 12 && Math.abs(dy) > Math.abs(dx)) {
                gesture = undefined;
                return;
            }
            if (Math.abs(dx) <= 12 || Math.abs(dx) <= Math.abs(dy)) return;
            gesture.dragging = true;
            stage.setPointerCapture(event.pointerId);
            stopLanding();
            stage.setAttribute('data-dragging', '');
        }
        if (!reducedMotion.matches) {
            stage.style.setProperty('--cargo-drag', `${Math.min(48, Math.max(-48, dx * .45))}px`);
        }
    });

    function finishGesture(event) {
        if (gesture?.id !== event.pointerId) return;
        const distance = event.clientX - gesture.x;
        suppressClick = gesture.dragging;
        gesture = undefined;
        stage.removeAttribute('data-dragging');
        stage.style.removeProperty('--cargo-drag');
        if (event.type === 'pointerup' && suppressClick && Math.abs(distance) >= 36) {
            advance(distance < 0 ? 1 : -1);
        }
    }

    stage.addEventListener('pointerup', finishGesture);
    stage.addEventListener('pointercancel', finishGesture);
    stage.addEventListener('lostpointercapture', finishGesture);
    stage.addEventListener('dragstart', event => event.preventDefault());
    stage.addEventListener('click', event => {
        if (suppressClick && event.detail > 0) {
            event.preventDefault();
            suppressClick = false;
            return;
        }
        const position = event.target.closest('[data-cargo-slide]')?.dataset.position;
        if (position === 'next' || position === 'previous') advance(position === 'next' ? 1 : -1);
    });
}
