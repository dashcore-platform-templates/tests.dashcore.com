<x-app-layout>
    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <livewire:workflows.wizard :workflow="$workflow ?? null" />
        </div>
    </div>
</x-app-layout>
