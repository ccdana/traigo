export function mountShoppingJourney(layout) {
    if (!layout || !('IntersectionObserver' in window)) return;

    const motion = window.matchMedia('(min-width: 920px) and (min-height: 640px) and (prefers-reduced-motion: no-preference)');
    const visual = layout.querySelector('.journey-visual');
    const steps = [...layout.querySelectorAll('.journey-steps > li')];
    let frame = 0;
    let inView = false;
    let active = -1;
    let stickyTop = 0;

    function update() {
        frame = 0;
        const bounds = layout.getBoundingClientRect();
        const distance = Math.max(1, bounds.height - visual.offsetHeight);
        const progress = Math.min(1, Math.max(0, (stickyTop - bounds.top) / distance));
        layout.style.setProperty('--journey-progress', progress.toFixed(4));

        const step = Math.round(progress * (steps.length - 1));
        if (step === active) return;
        steps[active]?.removeAttribute('data-current');
        steps[step].setAttribute('data-current', '');
        active = step;
    }

    function schedule() {
        if (motion.matches && inView && !frame) frame = requestAnimationFrame(update);
    }

    function measure() {
        if (motion.matches) stickyTop = Number.parseFloat(getComputedStyle(visual).top);
        schedule();
    }

    function syncMotion() {
        layout.toggleAttribute('data-moving', motion.matches);
        if (motion.matches) {
            measure();
            window.addEventListener('scroll', schedule, { passive: true });
            window.addEventListener('resize', measure);
        } else {
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', measure);
            if (frame) cancelAnimationFrame(frame);
            frame = 0;
            layout.style.removeProperty('--journey-progress');
            steps[active]?.removeAttribute('data-current');
            active = -1;
        }
    }

    const observer = new IntersectionObserver(([entry]) => {
        inView = entry.isIntersecting;
        schedule();
    });
    observer.observe(layout);
    motion.addEventListener('change', syncMotion);
    syncMotion();
}
