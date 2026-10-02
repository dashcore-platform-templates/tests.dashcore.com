<?php

namespace DashcoreTestsProbe\Subscribers;

use DashcoreTestsProbe\Recorder;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\ConsideredRiskySubscriber;

final class TestConsideredRisky implements ConsideredRiskySubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function notify(ConsideredRisky $event): void
    {
        $this->recorder->outcome('risky', $event->message());
    }
}
