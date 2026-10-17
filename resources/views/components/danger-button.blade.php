<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-(--ds-danger-fill) border border-transparent rounded-md font-semibold text-xs text-(--ds-text-strong) uppercase tracking-widest hover:bg-(--ds-danger-fill) active:bg-(--ds-danger-fill) focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 focus:ring-offset-surface-900 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
