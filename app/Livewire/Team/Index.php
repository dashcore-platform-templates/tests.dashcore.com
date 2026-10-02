<?php

namespace App\Livewire\Team;

use App\Enums\UserRole;
use App\Mail\UserInvitation as UserInvitationMail;
use App\Models\Invitation;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Index extends Component
{
    public bool $adminCanAccessFinance = false;

    public bool $showAddUserModal = false;

    #[Validate('required|string|max:255')]
    public string $newName = '';

    #[Validate('required|email|max:255|unique:users,email')]
    public string $newEmail = '';

    #[Validate('required|string|min:8')]
    public string $newPassword = '';

    #[Validate('required|in:owner,admin,finance,member')]
    public string $newRole = 'member';

    /** @var array<string, string> */
    public array $newOverrides = [];

    public bool $showInviteModal = false;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    /** @var array<string, string> */
    public array $inviteOverrides = [];

    public function mount(): void
    {
        $this->adminCanAccessFinance = (bool) Setting::get('admin_can_access_finance', false);
        $this->initializeNewOverrides();
        $this->initializeInviteOverrides();
    }

    public function changeRole(int $userId, string $role): void
    {
        if ($userId === auth()->id()) {
            return;
        }

        $roleEnum = UserRole::tryFrom($role);
        if (! $roleEnum) {
            return;
        }

        User::where('id', $userId)->update(['role' => $role]);

        $this->dispatch('role-changed');
    }

    public function removeUser(int $userId): void
    {
        if ($userId === auth()->id()) {
            return;
        }

        DB::transaction(function () use ($userId) {
            User::where('id', $userId)->delete();
        });

        $this->dispatch('user-removed');
    }

    public function toggleAdminFinanceAccess(): void
    {
        $this->adminCanAccessFinance = ! $this->adminCanAccessFinance;
        Setting::set('admin_can_access_finance', $this->adminCanAccessFinance);

        $this->dispatch('setting-saved');
    }

    public function openAddUserModal(): void
    {
        $this->resetAddUserForm();
        $this->showAddUserModal = true;
    }

    public function closeAddUserModal(): void
    {
        $this->showAddUserModal = false;
        $this->resetAddUserForm();
    }

    public function addUser(): void
    {
        $this->validate([
            'newName' => 'required|string|max:255',
            'newEmail' => 'required|email|max:255|unique:users,email',
            'newPassword' => 'required|string|min:8',
            'newRole' => 'required|in:owner,admin,finance,member',
        ]);

        DB::transaction(function () {
            $user = User::create([
                'name' => $this->newName,
                'email' => $this->newEmail,
                'password' => Hash::make($this->newPassword),
                'role' => $this->newRole,
            ]);

            foreach ($this->newOverrides as $key => $value) {
                if ($value === 'inherit') {
                    continue;
                }

                UserPermission::create([
                    'user_id' => $user->id,
                    'permission_key' => $key,
                    'access_level' => $value,
                ]);
            }
        });

        $this->closeAddUserModal();
        $this->dispatch('user-added');
    }

    public function openInviteModal(): void
    {
        $this->resetInviteForm();
        $this->showInviteModal = true;
    }

    public function closeInviteModal(): void
    {
        $this->showInviteModal = false;
        $this->resetInviteForm();
    }

    public function sendInvitation(): void
    {
        $this->validate([
            'inviteEmail' => 'required|email|max:255',
            'inviteRole' => 'required|in:owner,admin,finance,member',
        ]);

        if (User::where('email', $this->inviteEmail)->exists()) {
            $this->addError('inviteEmail', 'A user with this email already exists.');

            return;
        }

        $overridesToStore = array_filter(
            $this->inviteOverrides,
            fn ($value) => $value !== 'inherit'
        );

        $invitation = DB::transaction(function () use ($overridesToStore) {
            Invitation::where('email', $this->inviteEmail)
                ->whereNull('accepted_at')
                ->delete();

            return Invitation::create([
                'email' => $this->inviteEmail,
                'role' => $this->inviteRole,
                'token' => Invitation::generateToken(),
                'overrides' => $overridesToStore ?: null,
                'invited_by' => auth()->id(),
                'expires_at' => now()->addDays(7),
            ]);
        });

        Mail::to($invitation->email)->send(new UserInvitationMail($invitation));

        $this->closeInviteModal();
        $this->dispatch('invitation-sent');
    }

    public function resendInvitation(int $invitationId): void
    {
        $invitation = Invitation::findOrFail($invitationId);

        if ($invitation->isAccepted()) {
            return;
        }

        $invitation->update([
            'token' => Invitation::generateToken(),
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->send(new UserInvitationMail($invitation));

        $this->dispatch('invitation-resent');
    }

    public function cancelInvitation(int $invitationId): void
    {
        Invitation::where('id', $invitationId)
            ->whereNull('accepted_at')
            ->delete();

        $this->dispatch('invitation-cancelled');
    }

    private function resetAddUserForm(): void
    {
        $this->newName = '';
        $this->newEmail = '';
        $this->newPassword = '';
        $this->newRole = 'member';
        $this->initializeNewOverrides();
        $this->resetErrorBag();
    }

    private function resetInviteForm(): void
    {
        $this->inviteEmail = '';
        $this->inviteRole = 'member';
        $this->initializeInviteOverrides();
        $this->resetErrorBag();
    }

    private function initializeNewOverrides(): void
    {
        $this->newOverrides = [];
        foreach ($this->permissionKeys() as $key) {
            $this->newOverrides[$key] = 'inherit';
        }
    }

    private function initializeInviteOverrides(): void
    {
        $this->inviteOverrides = [];
        foreach ($this->permissionKeys() as $key) {
            $this->inviteOverrides[$key] = 'inherit';
        }
    }

    private function permissionKeys(): array
    {
        $keys = array_keys(config('permissions', []));

        return array_values(array_diff($keys, ['modules']));
    }

    public function render()
    {
        return view('livewire.team.index', [
            'users' => User::orderBy('name')->get(),
            'pendingInvitations' => Invitation::whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get(),
            'roles' => UserRole::cases(),
            'modules' => config('permissions.modules', []),
            'newRoleEnum' => UserRole::tryFrom($this->newRole),
            'inviteRoleEnum' => UserRole::tryFrom($this->inviteRole),
        ]);
    }
}
