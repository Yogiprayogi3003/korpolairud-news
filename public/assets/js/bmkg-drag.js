(() => {
    'use strict';

    const panel = document.getElementById('bmkgFloat');
    const header = panel?.querySelector('.bmkg-head');

    if (!panel || !header) return;

    const KEY = 'bmkg-floating-position';
    let dragging = false;
    let startX = 0, startY = 0;
    let startLeft = 0, startTop = 0;

    const clamp = (value, min, max) =>
        Math.min(Math.max(value, min), Math.max(min, max));

    function applyPosition(left, top) {
        const maxLeft = window.innerWidth - panel.offsetWidth;
        const maxTop = window.innerHeight - panel.offsetHeight;

        left = clamp(left, 0, maxLeft);
        top = clamp(top, 0, maxTop);

        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';

        return { left, top };
    }

    try {
        const saved = JSON.parse(localStorage.getItem(KEY));

        if (saved && Number.isFinite(saved.left) &&
            Number.isFinite(saved.top)) {
            requestAnimationFrame(() =>
                applyPosition(saved.left, saved.top)
            );
        }
    } catch (_) {}

    header.style.cursor = 'grab';
    header.style.touchAction = 'none';
    header.querySelector('.bmkg-close')?.style.setProperty(
        'touch-action', 'manipulation'
    );

    header.addEventListener('pointerdown', event => {
        if (event.target.closest('button')) return;
        if (event.button !== undefined && event.button !== 0) return;

        const rect = panel.getBoundingClientRect();

        dragging = true;
        startX = event.clientX;
        startY = event.clientY;
        startLeft = rect.left;
        startTop = rect.top;

        header.style.cursor = 'grabbing';
        panel.style.transition = 'none';

        header.setPointerCapture(event.pointerId);
        event.preventDefault();
    });

    header.addEventListener('pointermove', event => {
        if (!dragging) return;

        applyPosition(
            startLeft + event.clientX - startX,
            startTop + event.clientY - startY
        );
    });

    function stopDragging() {
        if (!dragging) return;

        dragging = false;
        header.style.cursor = 'grab';
        panel.style.transition = '';

        const rect = panel.getBoundingClientRect();

        try {
            localStorage.setItem(KEY, JSON.stringify({
                left: rect.left,
                top: rect.top
            }));
        } catch (_) {}
    }

    header.addEventListener('pointerup', stopDragging);
    header.addEventListener('pointercancel', stopDragging);
    window.addEventListener('resize', () => {
        if (dragging) return;

        const rect = panel.getBoundingClientRect();
        const pos = applyPosition(rect.left, rect.top);

        try {
            localStorage.setItem(KEY, JSON.stringify(pos));
        } catch (_) {}
    });
})();
