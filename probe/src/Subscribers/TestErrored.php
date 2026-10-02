<?php

namespace DashcoreTestsProbe\Subscribers;

use DashcoreTestsProbe\Recorder;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;

final class TestErrored implements ErroredSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function notify(Errored $event): void
    {
        $this->recorder->outcome('errored', $event->throwable()->message());
    }
}
