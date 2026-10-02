<?php

namespace DashcoreTestsProbe\Subscribers;

use DashcoreTestsProbe\Recorder;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

final class TestPrepared implements PreparedSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function notify(Prepared $event): void
    {
        $this->recorder->start($event->test());
    }
}
