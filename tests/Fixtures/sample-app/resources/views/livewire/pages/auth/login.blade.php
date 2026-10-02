<?php

use Livewire\Volt\Component;

new class extends Component
{
    public string $email = '';

    public function mount(): void {}

    public function login(): void
    {
        $normalise = fn (string $email) => strtolower($email);
        $this->email = $normalise($this->email);
        $this->redirect('/');
    }
}; ?>

<div>
    <button wire:click="login">Log in</button>
</div>
