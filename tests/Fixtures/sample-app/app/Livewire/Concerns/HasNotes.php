<?php

namespace App\Livewire\Concerns;

trait HasNotes
{
    use KeepsHistory;

    public string $notes = '';

    public function mountHasNotes(): void
    {
        $this->notes = '';
    }

    public function saveNotes(): void
    {
        $this->notes = trim($this->notes);
    }

    public function save(): void
    {
        $this->notes = 'never: the component has its own save';
    }
}
