<?php

namespace App\Demo;

use App\Models\Launch;
use Illuminate\Support\Carbon;

/**
 * Two client launches as `tests:launch` records them: last night's, scrubbed
 * on the pad because the countdown found the Stripe CLI signed out and the
 * sales stand-in down, and tonight's, which flew Subscription and
 * Provisioning and broke at First sign-in — with a placeholder screenshot
 * for every step it reached. Nothing was flown, paid for or built.
 */
class DemoLaunches
{
    private const COCKPIT = 'https://ceo.harbor-analytics.practice.dashcore.com';

    /**
     * @return int launches made
     */
    public function seed(): int
    {
        $this->scrubbed(Carbon::now()->subDay()->setTime(2, 30));
        $this->failed(Carbon::now()->subHours(2));

        return 2;
    }

    private function scrubbed(Carbon $at): void
    {
        Launch::create([
            'target' => 'practice',
            'outcome' => Launch::SCRUBBED,
            'countdown' => [
                ['check' => 'Stripe CLI signed in', 'go' => false, 'why' => 'run `stripe login` on this machine'],
                ['check' => 'website answers', 'go' => true, 'why' => null],
                ['check' => 'sales answers', 'go' => false, 'why' => 'https://sales.demo.practice.test/up answered 502'],
                ['check' => 'delivery answers', 'go' => true, 'why' => null],
                ['check' => 'fleets can build the client\'s fleet', 'go' => true, 'why' => null],
            ],
            'stages' => [],
            'teardown' => [],
            'is_demo' => true,
            'started_at' => $at,
            'finished_at' => $at->copy()->addSeconds(14),
        ]);
    }

    private function failed(Carbon $at): void
    {
        $launch = Launch::create([
            'target' => 'practice',
            'outcome' => Launch::FAILED,
            'countdown' => [
                ['check' => 'Stripe CLI signed in', 'go' => true, 'why' => null],
                ['check' => 'website answers', 'go' => true, 'why' => null],
                ['check' => 'sales answers', 'go' => true, 'why' => null],
                ['check' => 'delivery answers', 'go' => true, 'why' => null],
                ['check' => 'fleets can build the client\'s fleet', 'go' => true, 'why' => null],
            ],
            'stages' => [],
            'teardown' => [['stage' => 'cancellation', 'name' => 'Cancellation', 'passed' => true, 'seconds' => 96.4, 'walks' => [], 'said' => [], 'errors' => []]],
            'is_demo' => true,
            'started_at' => $at,
            'finished_at' => $at->copy()->addMinutes(11),
        ]);

        $shots = Demo::launchShots().'/'.$launch->id;

        $launch->update(['stages' => [
            $this->stage($shots, 'subscription', 'Subscription', 'The prospect is sold the API + CEO plan', 41.8, [
                'website' => ['name' => 'Website', 'base_url' => 'https://demo.practice.test'],
                'sales' => ['name' => 'Sales', 'base_url' => 'https://sales.demo.practice.test'],
                'delivery' => ['name' => 'Delivery', 'base_url' => 'https://dcos.demo.practice.test'],
            ], 'Jordan Blake of Harbor Analytics bought API + CEO at $300/mo', [], [
                ['visit', 'website', 'passed', 820, 'Jordan opens the lets-talk page'],
                ['click', 'website', 'passed', 1460, 'Jordan sends the enquiry'],
                ['visit', 'sales', 'passed', 690, 'A rep opens the new lead'],
                ['click', 'sales', 'passed', 2210, 'The rep sends it to DCOS for a payment link'],
                ['mail', 'delivery', 'passed', 310, 'The payment link arrives in Jordan\'s email'],
                ['stripe_checkout', 'delivery', 'passed', 6120, 'Jordan pays with the test card on Stripe'],
                ['see', 'delivery', 'passed', 4380, 'The portal shows API + CEO active, $300 a month'],
            ]),
            $this->stage($shots, 'provisioning', 'Provisioning', 'The paid order builds the client\'s fleet', 412.3, [
                'cockpit' => ['name' => 'Harbor Analytics\' cockpit', 'base_url' => self::COCKPIT],
            ], 'Harbor Analytics\' fleet was built', ['Harbor Analytics paid for API + CEO: fleets builds it now.', 'Built at harbor-analytics.practice in 6 min 32 s.'], [
                ['visit', 'cockpit', 'passed', 1940, 'Their cockpit answers'],
            ]),
            $this->stage($shots, 'first-sign-in', 'First sign-in', 'The owner sets a password and signs in', 9.6, [
                'cockpit' => ['name' => 'Harbor Analytics\' cockpit', 'base_url' => self::COCKPIT],
            ], '', ['login sent them nothing, so the build fell back to ceo\'s own password link.'], [
                ['mail', 'cockpit', 'passed', 280, 'The set-password mail arrives'],
                ['visit', 'cockpit', 'passed', 1120, 'Jordan opens the link'],
                ['fill', 'cockpit', 'failed', 5030, 'Jordan types a new password', 'This password reset token is invalid.'],
            ], ['Jordan signs in', 'Their dashboard greets Jordan'], ['First sign-in failed after 2 of 5 steps.']),
        ]]);
    }

    /**
     * One stage as `tests:journey --record` writes it, one walk, its screenshots
     * drawn under $shots.
     *
     * @param  array<string, array{name: string, base_url: string}>  $apps
     * @param  list<string>  $said
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: string, 5?: string}>  $steps
     * @param  list<string>  $notReached
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function stage(string $shots, string $key, string $name, string $journey, float $seconds, array $apps, string $summary, array $said, array $steps, array $notReached = [], array $errors = []): array
    {
        $dir = "{$shots}/{$key}-1";
        $walked = [];

        foreach ($steps as $position => $step) {
            [$do, $on, $status, $ms, $label] = $step;
            $file = sprintf('%03d.jpg', $position);
            $url = rtrim($apps[$on]['base_url'], '/').'/';

            Placeholder::write("{$dir}/{$file}", $url, $label, $status === 'failed');

            $walked[] = ['position' => $position, 'do' => $do, 'on' => $on, 'status' => $status, 'ms' => $ms, 'label' => $label, 'url' => $url, 'screenshot' => $file, 'error' => $step[5] ?? null];
        }

        return [
            'stage' => $key,
            'name' => $name,
            'journey' => $journey,
            'passed' => $errors === [],
            'seconds' => $seconds,
            'apps' => $apps,
            'walks' => [[
                'shots' => $dir,
                'summary' => $summary,
                'steps' => $walked,
                'not_reached' => $notReached,
                'server_errors' => [],
                'error' => null,
                'said' => $said,
            ]],
            'said' => [],
            'errors' => $errors,
        ];
    }
}
