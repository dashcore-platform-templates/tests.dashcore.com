<?php

namespace DashcoreTestsProbe\Subscribers;

use DashcoreTestsProbe\Recorder;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

final class TestFinished implements FinishedSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function notify(Finished $event): void
    {
        $this->recorder->finish($event->test(), $event->numberOfAssertionsPerformed());
    }
}
