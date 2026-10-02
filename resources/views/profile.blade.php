<x-app-layout>
    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-surface-100">Profile</h1>
                <p class="text-sm text-surface-400 mt-1">Manage your account settings.</p>
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
