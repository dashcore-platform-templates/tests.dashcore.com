<?php

namespace DashcoreTestsProbe\Subscribers;

use DashcoreTestsProbe\Recorder;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

final class TestSkipped implements SkippedSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function notify(Skipped $event): void
    {
        $this->recorder->outcome('skipped', $event->message());
    }
}
