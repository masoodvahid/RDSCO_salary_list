/*
 * Page-wide busy layer. When an action, or a page change with wire:navigate, takes longer than a second,
 * a frosted layer covers the whole page with a small animation, so nothing else can be clicked or typed
 * meanwhile. Background work never shows it: cell autosave, live sync and the refresh it triggers, and
 * plain typing into a search box.
 */
(() => {
    const DELAY = 1000;
    const BACKGROUND = new Set(['saveCells', 'changesSince', '$refresh']);

    let busy = 0;
    let timer = null;
    let layer = null;
    let shown = false;
    let navigating = false;
    let lastFocus = null;

    const typing = () => {
        const el = document.activeElement;
        if (!el) return false;
        if (el.tagName === 'TEXTAREA') return true;
        return el.tagName === 'INPUT' && !['checkbox', 'radio', 'button', 'submit', 'file', 'reset'].includes(el.type);
    };

    const build = () => {
        const el = document.createElement('div');
        el.className = 'busy-layer';
        el.hidden = true;
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.innerHTML = '<div class="busy-card"><span class="busy-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>'
            + '<span class="busy-text">لطفاً چند لحظه صبر کنید…</span></div>';
        return el;
    };

    // Keys are held back while busy (browser shortcuts with Ctrl/Alt/⌘ and F-keys still work).
    const holdKeys = (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey || /^F\d+$/.test(e.key)) return;
        e.preventDefault();
        e.stopPropagation();
    };

    const show = () => {
        timer = null;
        if (!layer || !document.body.contains(layer)) {
            layer = build();
            document.body.appendChild(layer);
        }
        shown = true;
        lastFocus = document.activeElement;
        if (lastFocus && lastFocus !== document.body) lastFocus.blur();
        layer.hidden = false;
        requestAnimationFrame(() => layer.classList.add('is-visible'));
        document.body.setAttribute('aria-busy', 'true');
        window.addEventListener('keydown', holdKeys, true);
    };

    const hide = () => {
        clearTimeout(timer);
        timer = null;
        if (!shown) return;
        shown = false;
        layer.classList.remove('is-visible');
        layer.hidden = true;
        document.body.removeAttribute('aria-busy');
        window.removeEventListener('keydown', holdKeys, true);
        if (lastFocus && document.body.contains(lastFocus) && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
        lastFocus = null;
    };

    const start = () => {
        busy += 1;
        if (busy === 1 && !shown) {
            clearTimeout(timer);
            timer = setTimeout(show, DELAY);
        }
    };

    const end = () => {
        busy = Math.max(0, busy - 1);
        if (busy === 0) hide();
    };

    document.addEventListener('livewire:init', () => {
        window.Livewire.interceptMessage(({ message, onSend, onFinish }) => {
            let counted = false;
            onSend(({ payload } = {}) => {
                const calls = (payload?.calls || message?.calls || []).map((call) => call.method);
                if (calls.length && calls.every((method) => BACKGROUND.has(method))) return;
                if (!calls.length && typing()) return;
                counted = true;
                start();
            });
            onFinish(() => {
                if (!counted) return;
                counted = false;
                end();
            });
        });
    });

    document.addEventListener('livewire:navigate', () => {
        if (navigating) return;
        navigating = true;
        start();
    });
    document.addEventListener('livewire:navigated', () => {
        if (!navigating) return;
        navigating = false;
        end();
    });

    // For tests and debugging.
    window.busyLayer = { start, end, get visible() { return shown; } };
})();
