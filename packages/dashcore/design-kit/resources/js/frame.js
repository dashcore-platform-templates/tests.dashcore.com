// The shell's drawer is a checkbox the sidebar is a peer of, so it opens and
// closes without any of this. The one thing native markup will not do is
// close on Escape, and hand the keyboard back to the control that opened it.
//
// Imported from each app's own app.js:
//
//     import '../../vendor/dashcore/design-kit/resources/js/frame.js';
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    const drawer = document.querySelector('input[data-dc-drawer]');

    if (drawer?.checked) {
        drawer.checked = false;
        drawer.focus();
    }
});

// The theme toggle. The saved choice is applied before paint by
// <x-ds::theme-script>; this flips it, stores it under the key that
// script recorded, and keeps each toggle's label naming where it will go.
const root = document.documentElement;

// The key and default come from the <x-ds::theme-script> tag on the
// current page, read fresh every time. wire:navigate copies the next page's
// <html> attributes over the live ones, so nothing stored there survives a
// swap, and a value captured once at load is wrong whenever the visit began on
// a page without the tag.
const themeConfig = () => {
    const tag = document.querySelector('script[data-dc-theme-key]');

    return {
        key: tag?.dataset.dcThemeKey || 'dc-theme',
        fallback: tag?.dataset.dcThemeDefault || 'system',
        present: tag !== null,
    };
};

const stored = (key) => {
    try {
        return localStorage.getItem(key);
    } catch (e) {
        return null;
    }
};

const applyTheme = () => {
    const { key, fallback, present } = themeConfig();

    // A page with no theme-script has no switch to honour; leave it alone.
    if (!present) return;

    const theme = stored(key) || fallback;

    if (theme === 'dark' || theme === 'light') {
        root.classList.remove('dark', 'light');
        root.classList.add(theme);

        if (root.hasAttribute('data-theme')) root.dataset.theme = theme;
    }
};

const isDark = () => {
    if (root.classList.contains('dark')) return true;
    if (root.classList.contains('light')) return false;

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

// The visible label is chosen by CSS from the <html> class, so it is right
// before this script loads; this keeps the pressed state in step for
// assistive tech.
const labelToggles = () => {
    document.querySelectorAll('[data-dc-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', String(isDark()));
    });
};

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-dc-theme-toggle]')) return;

    const next = isDark() ? 'light' : 'dark';

    root.classList.remove('dark', 'light');
    root.classList.add(next);

    if (root.hasAttribute('data-theme')) root.dataset.theme = next;

    try {
        localStorage.setItem(themeConfig().key, next);
    } catch (e) {}

    labelToggles();
});

document.addEventListener('DOMContentLoaded', labelToggles);

// Livewire 3.5+ lets a listener run inside the swap, before paint, so a dark
// page never flashes light between two pages. `navigated` is the fallback for
// older Livewire, and relabels the toggles either way.
document.addEventListener('livewire:navigating', (event) => {
    event.detail?.onSwap?.(applyTheme);
});

document.addEventListener('livewire:navigated', () => {
    applyTheme();
    labelToggles();
});
