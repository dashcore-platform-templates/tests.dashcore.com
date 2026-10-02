<?php

namespace DashcoreTestsProbe\Subscribers;

use DashcoreTestsProbe\Recorder;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;

final class TestPassed implements PassedSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function notify(Passed $event): void
    {
        $this->recorder->outcome('passed');
    }
}
