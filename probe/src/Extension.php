<?php

namespace DashcoreTestsProbe;

use PHPUnit\Runner\Extension\Extension as PhpunitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The PHPUnit extension tests.dashcore.com attaches to a fleet app's suite.
 *
 * It records, per test, what the test body actually exercised: the routes it
 * matched, the Livewire components it mounted and the actions it called, the
 * artisan commands and queued jobs it ran, and the lines of `app/` it executed.
 * That is the evidence a surface is tested — not a file name, not a guess.
 *
 * Output is one JSON line per test, written to DASHCORE_PROBE_OUT. With that
 * variable unset the extension does nothing, so it can never change a suite's
 * result on its own.
 */
final class Extension implements PhpunitExtension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $out = getenv('DASHCORE_PROBE_OUT');
        $root = getenv('DASHCORE_PROBE_ROOT');

        if (! is_string($out) || $out === '' || ! is_string($root) || $root === '') {
            return;
        }

        $recorder = new Recorder($out, rtrim($root, '/'));

        $facade->registerSubscribers(
            new Subscribers\TestPrepared($recorder),
            new Subscribers\TestPassed($recorder),
            new Subscribers\TestFailed($recorder),
            new Subscribers\TestErrored($recorder),
            new Subscribers\TestSkipped($recorder),
            new Subscribers\TestMarkedIncomplete($recorder),
            new Subscribers\TestConsideredRisky($recorder),
            new Subscribers\TestFinished($recorder),
        );
    }
}
