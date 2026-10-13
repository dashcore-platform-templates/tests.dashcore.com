<?php

use App\Livewire\Forms\LoginForm;
use Dashcore\Bridge\Sso\SsoMode;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <div class="text-center mb-6">
        <h1 class="text-2xl font-bold text-surface-100 tracking-tight mb-1">Welcome back</h1>
        <p class="text-sm text-surface-400">Sign in to your Dashcore account</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    @if (session('bridge.sso.error'))
        <div class="mb-4 rounded-lg bg-red-500/10 border border-red-500/20 px-4 py-3 text-sm text-red-400">
            {{ session('bridge.sso.error') }}
        </div>
    @endif

    @if (SsoMode::isOn())
        <a href="{{ route('bridge.sso.redirect') }}" class="btn-cyan w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-medium rounded-lg transition-colors mb-4">
            Sign in with Dashcore
        </a>
    @endif

    @if (! SsoMode::isRequired() || request()->query->has('local'))
        <form wire:submit="login" class="space-y-4">
            @if (SsoMode::isOn())
                <div class="relative my-4">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-surface-800"></div></div>
                    <div class="relative flex justify-center text-xs"><span class="bg-surface-950 px-2 text-surface-500">or use a password</span></div>
                </div>
            @endif

            <!-- Email Address -->
            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input wire:model="form.email" id="email" class="block mt-1 w-full" type="email" name="email" required autofocus autocomplete="username" placeholder="you@example.com" />
                <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
            </div>

            <!-- Password -->
            <div>
                <div class="flex items-center justify-between mb-1">
                    <x-input-label for="password" :value="__('Password')" />
                    @if (Route::has('password.request'))
                        <a class="text-xs text-surface-400 hover:text-accent-400 transition-colors" href="{{ route('password.request') }}" wire:navigate>
                            {{ __('Forgot password?') }}
                        </a>
                    @endif
                </div>

                <x-text-input wire:model="form.password" id="password" class="block w-full"
                                type="password"
                                name="password"
                                required autocomplete="current-password"
                                placeholder="••••••••" />

                <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
            </div>

            <!-- Remember Me -->
            <label for="remember" class="inline-flex items-center cursor-pointer">
                <input wire:model="form.remember" id="remember" type="checkbox" class="rounded bg-surface-800 border-surface-700 text-accent-500 shadow-sm focus:ring-accent-500" name="remember">
                <span class="ms-2 text-sm text-surface-400">{{ __('Remember me') }}</span>
            </label>

            <button type="submit" wire:loading.attr="disabled" class="btn-cyan w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-medium rounded-lg transition-colors disabled:opacity-50">
                <span wire:loading.remove wire:target="login">{{ __('Log in') }}</span>
                <span wire:loading wire:target="login" class="inline-flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Signing in…
                </span>
            </button>
        </form>
    @elseif (SsoMode::isRequired())
        <p class="text-center text-xs text-surface-500 mt-2">
            <a href="?local" class="text-surface-400 hover:text-accent-400 transition-colors">Break-glass sign-in</a>
        </p>
    @endif
</div>
