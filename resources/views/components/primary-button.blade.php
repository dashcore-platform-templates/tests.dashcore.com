<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-(--ds-accent) border border-transparent rounded-md font-semibold text-xs text-(--ds-on-accent) uppercase tracking-widest hover:bg-(--ds-accent-hover) focus:bg-(--ds-accent) active:bg-(--ds-accent) focus:outline-none focus:ring-2 focus:ring-(--ds-accent) focus:ring-offset-2 focus:ring-offset-surface-900 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
