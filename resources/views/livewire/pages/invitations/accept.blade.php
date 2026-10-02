<?php

use App\Models\Invitation;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $token = '';

    public ?Invitation $invitation = null;

    public string $status = 'valid';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $invitation = Invitation::where('token', $token)->first();

        if (! $invitation) {
            $this->status = 'invalid';

            return;
        }

        if ($invitation->isAccepted()) {
            $this->status = 'accepted';
            $this->invitation = $invitation;

            return;
        }

        if ($invitation->isExpired()) {
            $this->status = 'expired';
            $this->invitation = $invitation;

            return;
        }

        if (User::where('email', $invitation->email)->exists()) {
            $this->status = 'email_taken';
            $this->invitation = $invitation;

            return;
        }

        $this->invitation = $invitation;
    }

    public function accept(): void
    {
        if ($this->status !== 'valid' || ! $this->invitation) {
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () {
            $user = User::create([
                'name' => $this->name,
                'email' => $this->invitation->email,
                'password' => Hash::make($this->password),
                'role' => $this->invitation->role->value,
            ]);

            User::query()->where('id', $user->id)->update([
                'email_verified_at' => now(),
            ]);

            foreach (($this->invitation->overrides ?? []) as $key => $value) {
                if ($value === 'inherit') {
                    continue;
                }

                UserPermission::create([
                    'user_id' => $user->id,
                    'permission_key' => $key,
                    'access_level' => $value,
                ]);
            }

            $this->invitation->update(['accepted_at' => now()]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    @if ($status === 'invalid')
        <div class="text-center">
            <h1 class="text-2xl font-bold text-surface-100 mb-2">Invitation not found</h1>
            <p class="text-sm text-surface-400 mb-6">This invitation link doesn't exist or has been revoked.</p>
            <a href="{{ route('login') }}" wire:navigate class="inline-block text-sm text-accent-400 hover:text-accent-500 transition-colors">&larr; Back to login</a>
        </div>
    @elseif ($status === 'accepted')
        <div class="text-center">
            <h1 class="text-2xl font-bold text-surface-100 mb-2">Already accepted</h1>
            <p class="text-sm text-surface-400 mb-6">This invitation has already been used. Please sign in instead.</p>
            <a href="{{ route('login') }}" wire:navigate class="inline-block text-sm text-accent-400 hover:text-accent-500 transition-colors">Go to login &rarr;</a>
        </div>
    @elseif ($status === 'expired')
        <div class="text-center">
            <h1 class="text-2xl font-bold text-surface-100 mb-2">Invitation expired</h1>
            <p class="text-sm text-surface-400 mb-6">This invitation expired on {{ $invitation->expires_at->format('M j, Y') }}. Ask your admin to send a new one.</p>
            <a href="{{ route('login') }}" wire:navigate class="inline-block text-sm text-accent-400 hover:text-accent-500 transition-colors">&larr; Back to login</a>
        </div>
    @elseif ($status === 'email_taken')
        <div class="text-center">
            <h1 class="text-2xl font-bold text-surface-100 mb-2">Email already in use</h1>
            <p class="text-sm text-surface-400 mb-6">An account already exists for {{ $invitation->email }}. Please sign in.</p>
            <a href="{{ route('login') }}" wire:navigate class="inline-block text-sm text-accent-400 hover:text-accent-500 transition-colors">Go to login &rarr;</a>
        </div>
    @else
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-surface-100 tracking-tight mb-1">Accept your invitation</h1>
            <p class="text-sm text-surface-400">Joining as <span class="text-accent-400 font-medium">{{ ucfirst($invitation->role->value) }}</span> · {{ $invitation->email }}</p>
        </div>

        <form wire:submit="accept" class="space-y-4">
            <div>
                <x-input-label for="name" :value="__('Full name')" />
                <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" name="name" required autofocus autocomplete="name" placeholder="Your name" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input wire:model="password" id="password" class="block mt-1 w-full"
                              type="password"
                              name="password"
                              required autocomplete="new-password"
                              placeholder="••••••••" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password_confirmation" :value="__('Confirm password')" />
                <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full"
                              type="password"
                              name="password_confirmation" required autocomplete="new-password"
                              placeholder="••••••••" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
            </div>

            <button type="submit" wire:loading.attr="disabled" class="btn-cyan w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-medium rounded-lg transition-colors disabled:opacity-50">
                <span wire:loading.remove wire:target="accept">Accept &amp; create account</span>
                <span wire:loading wire:target="accept" class="inline-flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Creating account…
                </span>
            </button>
        </form>
    @endif
</div>
