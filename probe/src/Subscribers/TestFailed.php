<?php

namespace DashcoreTestsProbe\Subscribers;

use DashcoreTestsProbe\Recorder;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;

final class TestFailed implements FailedSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function notify(Failed $event): void
    {
        $this->recorder->outcome('failed', $event->throwable()->message());
    }
}
