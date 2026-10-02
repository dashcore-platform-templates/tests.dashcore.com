<?php

namespace App\Livewire\Settings;

use Livewire\Component;

class AiSettings extends Component
{
    public function toggle(): void
    {
        $this->dispatch('toggled');
    }
}
