<?php

namespace App\Livewire\Concerns;

trait KeepsHistory
{
    public function undo(): void
    {
        $this->notes = '';
    }
}
