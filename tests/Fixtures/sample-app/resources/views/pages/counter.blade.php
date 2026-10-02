<?php

use function Livewire\Volt\{state};

state(['count' => 0]);

$increment = fn () => $this->count++;

$reset = function () {
    $this->count = 0;
};

?>

<div>{{ $count }}</div>
