<div>
    {{-- Action Buttons --}}
    <div class="flex justify-end gap-2 mb-4">
        <button
            wire:click="openInviteModal"
            class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-surface-800 text-surface-200 text-sm font-medium hover:bg-surface-700 transition-colors"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
            Invite User
        </button>
        <button
            wire:click="openAddUserModal"
            class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-accent-500 text-white text-sm font-medium hover:bg-accent-600 transition-colors"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
            Add New User
        </button>
    </div>

    {{-- Admin Finance Access Toggle --}}
    <div class="card-os p-4 mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-sm font-medium text-surface-200">Admin can access money pages</h3>
                <p class="text-xs text-surface-500 mt-0.5">When enabled, Admins can view Finance-gated pages like billing.</p>
            </div>
            <button
                wire:click="toggleAdminFinanceAccess"
                wire:loading.attr="disabled"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2 focus:ring-offset-surface-900 disabled:opacity-50 {{ $adminCanAccessFinance ? 'bg-accent-500' : 'bg-surface-700' }}"
                role="switch"
                aria-checked="{{ $adminCanAccessFinance ? 'true' : 'false' }}"
            >
                <span wire:loading.remove wire:target="toggleAdminFinanceAccess" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $adminCanAccessFinance ? 'translate-x-5' : 'translate-x-0' }}"></span>
                <span wire:loading wire:target="toggleAdminFinanceAccess" class="absolute inset-0 flex items-center justify-center">
                    <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </span>
            </button>
        </div>
        <x-action-message class="mt-2" on="setting-saved">
            Setting saved.
        </x-action-message>
    </div>

    {{-- Team Members Table --}}
    <div class="card-os overflow-hidden">
        <table class="min-w-full divide-y divide-surface-800">
            <thead class="bg-surface-900/50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-surface-400 uppercase tracking-wider">Name</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-surface-400 uppercase tracking-wider">Email</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-surface-400 uppercase tracking-wider">Role</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-surface-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-surface-800">
                @foreach ($users as $user)
                    <tr class="hover:bg-surface-800/30 transition-colors" wire:key="user-{{ $user->id }}">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-surface-200">{{ $user->name }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-surface-400">{{ $user->email }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if ($user->id === auth()->id())
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-800 text-surface-300">
                                    {{ ucfirst($user->role->value) }}
                                </span>
                            @else
                                <div class="relative" wire:key="role-select-{{ $user->id }}">
                                    <select
                                        wire:change="changeRole({{ $user->id }}, $event.target.value)"
                                        wire:loading.attr="disabled"
                                        wire:target="changeRole({{ $user->id }}, '{{ $user->role->value }}')"
                                        class="block w-full rounded-md border-0 bg-surface-800 py-1.5 pl-3 pr-10 text-surface-200 text-sm ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500 disabled:opacity-50"
                                    >
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->value }}" {{ $user->role === $role ? 'selected' : '' }}>
                                                {{ ucfirst($role->value) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div wire:loading wire:target="changeRole({{ $user->id }})" class="absolute inset-y-0 right-8 flex items-center">
                                        <svg class="animate-spin h-4 w-4 text-accent-400" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                    </div>
                                </div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            @if ($user->id === auth()->id())
                                <span class="text-surface-600 text-xs">You</span>
                            @else
                                <button
                                    wire:click="removeUser({{ $user->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="removeUser({{ $user->id }})"
                                    wire:confirm="Are you sure you want to remove {{ $user->name }} from the team?"
                                    class="inline-flex items-center gap-1 text-red-400 hover:text-red-300 disabled:opacity-50 transition-colors"
                                >
                                    <span wire:loading.remove wire:target="removeUser({{ $user->id }})">Remove</span>
                                    <span wire:loading wire:target="removeUser({{ $user->id }})" class="inline-flex items-center gap-1">
                                        <svg class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Removing...
                                    </span>
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <x-action-message class="mt-4" on="role-changed">
        Role updated.
    </x-action-message>

    <x-action-message class="mt-4" on="user-removed">
        User removed.
    </x-action-message>

    <x-action-message class="mt-4" on="user-added">
        User added.
    </x-action-message>

    <x-action-message class="mt-4" on="invitation-sent">
        Invitation sent.
    </x-action-message>

    <x-action-message class="mt-4" on="invitation-resent">
        Invitation resent.
    </x-action-message>

    <x-action-message class="mt-4" on="invitation-cancelled">
        Invitation cancelled.
    </x-action-message>

    {{-- Pending Invitations --}}
    @if ($pendingInvitations->isNotEmpty())
        <div class="mt-8">
            <h3 class="text-sm font-semibold text-surface-200 mb-2">Pending invitations</h3>
            <div class="card-os overflow-hidden">
                <table class="min-w-full divide-y divide-surface-800">
                    <thead class="bg-surface-900/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-surface-400 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-surface-400 uppercase tracking-wider">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-surface-400 uppercase tracking-wider">Sent</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-surface-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-800">
                        @foreach ($pendingInvitations as $invitation)
                            <tr class="hover:bg-surface-800/30 transition-colors" wire:key="invite-{{ $invitation->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-surface-200">{{ $invitation->email }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-800 text-surface-300">
                                        {{ ucfirst($invitation->role->value) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-surface-400">{{ $invitation->created_at->diffForHumans() }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <button
                                        wire:click="resendInvitation({{ $invitation->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="resendInvitation({{ $invitation->id }})"
                                        class="text-accent-400 hover:text-accent-300 disabled:opacity-50 transition-colors mr-4"
                                    >
                                        <span wire:loading.remove wire:target="resendInvitation({{ $invitation->id }})">Resend</span>
                                        <span wire:loading wire:target="resendInvitation({{ $invitation->id }})">Sending…</span>
                                    </button>
                                    <button
                                        wire:click="cancelInvitation({{ $invitation->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="cancelInvitation({{ $invitation->id }})"
                                        wire:confirm="Cancel invitation to {{ $invitation->email }}?"
                                        class="text-red-400 hover:text-red-300 disabled:opacity-50 transition-colors"
                                    >Cancel</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Add New User Modal --}}
    @if ($showAddUserModal)
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-6"
         wire:click.self="closeAddUserModal"
         x-data
         x-on:keydown.escape.window="$wire.closeAddUserModal()"
    >
        <div class="fixed inset-0 bg-black/75" wire:click="closeAddUserModal"></div>
        <div class="relative w-full sm:max-w-2xl bg-surface-900 border border-surface-800/60 rounded-lg shadow-xl my-8 z-10">
        <form wire:submit.prevent="addUser" class="p-6">
            <h2 class="text-lg font-semibold text-surface-100 mb-1">Add new user</h2>
            <p class="text-xs text-surface-400 mb-6">Set the role and any per-user permission overrides at creation time.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="newName" class="block text-xs font-medium text-surface-300 mb-1">Name</label>
                    <input wire:model.blur="newName" id="newName" type="text" class="block w-full rounded-md border-0 bg-surface-800 py-1.5 px-3 text-sm text-surface-200 ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500" />
                    @error('newName') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="newEmail" class="block text-xs font-medium text-surface-300 mb-1">Email</label>
                    <input wire:model.blur="newEmail" id="newEmail" type="email" class="block w-full rounded-md border-0 bg-surface-800 py-1.5 px-3 text-sm text-surface-200 ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500" />
                    @error('newEmail') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="newPassword" class="block text-xs font-medium text-surface-300 mb-1">Password</label>
                    <input wire:model.blur="newPassword" id="newPassword" type="password" class="block w-full rounded-md border-0 bg-surface-800 py-1.5 px-3 text-sm text-surface-200 ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500" />
                    @error('newPassword') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="newRole" class="block text-xs font-medium text-surface-300 mb-1">Role</label>
                    <select wire:model.live="newRole" id="newRole" class="block w-full rounded-md border-0 bg-surface-800 py-1.5 px-3 pr-10 text-sm text-surface-200 ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500">
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}">{{ ucfirst($role->value) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <h3 class="text-xs font-semibold text-surface-300 uppercase tracking-wider mt-6 mb-2">Permission overrides</h3>

            @include('livewire.admin._permission-grid', [
                'role' => $newRoleEnum,
                'modules' => $modules,
                'overrides' => $newOverrides,
                'wirePrefix' => 'newOverrides',
            ])

            <div class="flex justify-end gap-3 mt-6">
                <button
                    type="button"
                    wire:click="closeAddUserModal"
                    class="px-4 py-2 rounded-md bg-surface-800 text-surface-200 text-sm font-medium hover:bg-surface-700 transition-colors"
                >Cancel</button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="addUser"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-accent-500 text-white text-sm font-medium hover:bg-accent-600 disabled:opacity-50 transition-colors"
                >
                    <span wire:loading.remove wire:target="addUser">Create user</span>
                    <span wire:loading wire:target="addUser" class="inline-flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Creating…
                    </span>
                </button>
            </div>
        </form>
        </div>
    </div>
    @endif

    {{-- Invite User Modal --}}
    @if ($showInviteModal)
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-6"
         wire:click.self="closeInviteModal"
         x-data
         x-on:keydown.escape.window="$wire.closeInviteModal()"
    >
        <div class="fixed inset-0 bg-black/75" wire:click="closeInviteModal"></div>
        <div class="relative w-full sm:max-w-2xl bg-surface-900 border border-surface-800/60 rounded-lg shadow-xl my-8 z-10">
        <form wire:submit.prevent="sendInvitation" class="p-6">
            <h2 class="text-lg font-semibold text-surface-100 mb-1">Invite a user</h2>
            <p class="text-xs text-surface-400 mb-6">Send an email invitation. They'll set their own name and password when they accept.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="inviteEmail" class="block text-xs font-medium text-surface-300 mb-1">Email</label>
                    <input wire:model.blur="inviteEmail" id="inviteEmail" type="email" class="block w-full rounded-md border-0 bg-surface-800 py-1.5 px-3 text-sm text-surface-200 ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500" />
                    @error('inviteEmail') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="inviteRole" class="block text-xs font-medium text-surface-300 mb-1">Role</label>
                    <select wire:model.live="inviteRole" id="inviteRole" class="block w-full rounded-md border-0 bg-surface-800 py-1.5 px-3 pr-10 text-sm text-surface-200 ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500">
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}">{{ ucfirst($role->value) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <h3 class="text-xs font-semibold text-surface-300 uppercase tracking-wider mt-6 mb-2">Permission overrides</h3>

            @include('livewire.admin._permission-grid', [
                'role' => $inviteRoleEnum,
                'modules' => $modules,
                'overrides' => $inviteOverrides,
                'wirePrefix' => 'inviteOverrides',
            ])

            <div class="flex justify-end gap-3 mt-6">
                <button
                    type="button"
                    wire:click="closeInviteModal"
                    class="px-4 py-2 rounded-md bg-surface-800 text-surface-200 text-sm font-medium hover:bg-surface-700 transition-colors"
                >Cancel</button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="sendInvitation"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-accent-500 text-white text-sm font-medium hover:bg-accent-600 disabled:opacity-50 transition-colors"
                >
                    <span wire:loading.remove wire:target="sendInvitation">Send invitation</span>
                    <span wire:loading wire:target="sendInvitation" class="inline-flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Sending…
                    </span>
                </button>
            </div>
        </form>
        </div>
    </div>
    @endif
</div>
