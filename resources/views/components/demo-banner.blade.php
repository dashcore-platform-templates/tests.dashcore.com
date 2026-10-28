{{-- Shown on every page while tests:demo's made-up data is in the database. --}}
@if (\App\Demo\Demo::active())
    <div role="note" data-demo-banner class="border-b border-(--ds-warning) bg-(--ds-warning-soft) px-4 py-2 text-center text-sm text-(--ds-text-strong)">
        <span class="font-semibold text-(--ds-warning)">Demo data.</span>
        The apps, scans, runs and launches marked demo are made up to show how each page works — nothing here was scanned or run.
        Clear them with <code class="font-mono text-xs">php artisan tests:demo --wipe</code>.
    </div>
@endif
