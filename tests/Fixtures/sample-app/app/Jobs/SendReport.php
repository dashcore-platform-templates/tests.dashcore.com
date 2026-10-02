<?php

namespace App\Jobs;

class SendReport
{
    public function __construct(public int $id) {}

    public function handle(): void
    {
        $total = $this->id * 2;
        logger()->info('sent', ['total' => $total]);
    }

    public function failed(): void
    {
        logger()->error('failed');
    }
}
