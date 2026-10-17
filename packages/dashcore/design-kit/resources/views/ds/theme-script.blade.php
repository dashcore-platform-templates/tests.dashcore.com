@props([
    // Where the choice is kept. An app moving onto the shell passes its old
    // key so nobody's saved preference is lost in the move.
    'storageKey' => 'dc-theme',
    // What an app shows before anyone chooses: 'system', 'light' or 'dark'.
    'default' => 'system',
])

{{-- In <head>, before any stylesheet paints: applies a saved choice as a
     dark/light class on <html>, which is what the tokens read. Inline and
     synchronous on purpose — run any later and a dark-mode user sees a white
     flash on every page load. --}}
{{-- The key and default ride on the tag itself, server-rendered, so ui.js can
     read them from whichever page is current. Captured once from the first
     page instead, a visit that began on a page without this tag (a login
     screen) would keep the wrong key for the rest of the session. --}}
<script data-dc-theme-key="{{ $storageKey }}" data-dc-theme-default="{{ $default }}">
    (() => {
        const root = document.documentElement;
        const key = @js($storageKey);
        let theme = null;

        try {
            theme = localStorage.getItem(key);
        } catch (e) {}

        theme = theme || @js($default);

        if (theme === 'dark' || theme === 'light') {
            root.classList.remove('dark', 'light');
            root.classList.add(theme);

            if (root.hasAttribute('data-theme')) root.dataset.theme = theme;
        }
    })();
</script>
