<?php

namespace App\Livewire\Settings;

use Livewire\Component;

class Index extends Component
{
    public string $name = '';

    public function mount(): void
    {
        $this->name = 'x';
    }

    public function updatedName(): void
    {
        $this->name = trim($this->name);
    }

    public function save(): void
    {
        $this->validate(['name' => 'required']);
    }

    public function render()
    {
        return view('livewire.settings');
    }
}
