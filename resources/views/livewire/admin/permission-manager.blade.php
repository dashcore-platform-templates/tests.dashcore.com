<div>
    {{-- User selector --}}
    <div class="card-os p-4 mb-6">
        <label for="selectedUserId" class="block text-sm font-medium text-surface-200 mb-2">Select user</label>
        <select
            wire:model.live="selectedUserId"
            id="selectedUserId"
            class="block w-full rounded-md border-0 bg-surface-800 py-2 pl-3 pr-10 text-surface-200 text-sm ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500"
        >
            <option value="">— choose a user —</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}">
                    {{ $user->name }} ({{ ucfirst($user->role->value) }}){{ $user->id === auth()->id() ? ' — you' : '' }}
                </option>
            @endforeach
        </select>

        <div wire:loading wire:target="selectedUserId" class="text-xs text-surface-500 mt-2">Loading overrides…</div>
    </div>

    @if ($selectedUser)
        @include('livewire.admin._permission-grid', [
            'role' => $selectedUser->role,
            'modules' => $modules,
            'overrides' => $overrides,
            'wirePrefix' => 'overrides',
        ])

        {{-- Save / Reset --}}
        <div class="flex items-center gap-3">
            <button
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-accent-500 text-white text-sm font-medium hover:bg-accent-600 disabled:opacity-50 transition-colors"
            >
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Saving…
                </span>
            </button>

            <button
                wire:click="resetOverrides"
                wire:loading.attr="disabled"
                wire:target="resetOverrides"
                class="inline-flex items-center px-4 py-2 rounded-md bg-surface-800 text-surface-200 text-sm font-medium hover:bg-surface-700 disabled:opacity-50 transition-colors"
            >
                <span wire:loading.remove wire:target="resetOverrides">Reset</span>
                <span wire:loading wire:target="resetOverrides">Resetting…</span>
            </button>

            <x-action-message on="overrides-saved">Overrides saved.</x-action-message>
            <x-action-message on="overrides-reset">Overrides reset.</x-action-message>
            <x-action-message on="self-deny-prevented">Cannot deny yourself access to the Permissions page.</x-action-message>
        </div>
    @else
        <div class="card-os p-8 text-center text-sm text-surface-400">
            Select a user above to view and manage their permission overrides.
        </div>
    @endif
</div>
