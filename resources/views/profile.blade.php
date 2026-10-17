<x-app-layout>
    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 space-y-6">
            <div>
                <h1 class="text-2xl font-semibold text-(--ds-text-strong)">Profile</h1>
                <p class="text-sm text-(--ds-text-muted) mt-1">Manage your account settings.</p>
            </div>

            <div class="card-os p-6">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </div>

            <div class="card-os p-6">
                <div class="max-w-xl">
                    <livewire:profile.update-password-form />
                </div>
            </div>

            <div class="card-os p-6">
                <div class="max-w-xl">
                    <livewire:profile.delete-user-form />
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
