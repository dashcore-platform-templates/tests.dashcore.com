<?php

use Livewire\Volt\Component;

new class extends Component
{
    public string $email = '';

    public function mount(): void {}

    public function login(): void
    {
        $this->redirect('/');
    }
}; ?>

<div>
    <button wire:click="login">Log in</button>
</div>
