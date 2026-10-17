<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-(--ds-sunken) border border-(--ds-border) rounded-md font-semibold text-xs text-(--ds-text) uppercase tracking-widest shadow-sm hover:bg-(--ds-border) focus:outline-none focus:ring-2 focus:ring-(--ds-accent) focus:ring-offset-2 focus:ring-offset-surface-900 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
