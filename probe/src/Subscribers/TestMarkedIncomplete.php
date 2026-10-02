<?php

namespace DashcoreTestsProbe\Subscribers;

use DashcoreTestsProbe\Recorder;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;

final class TestMarkedIncomplete implements MarkedIncompleteSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function notify(MarkedIncomplete $event): void
    {
        $this->recorder->outcome('incomplete', $event->throwable()->message());
    }
}
