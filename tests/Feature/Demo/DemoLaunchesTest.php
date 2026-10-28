<?php

namespace Tests\Feature\Demo;

use App\Demo\Demo;
use App\Models\Launch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDemoScratch;
use Tests\TestCase;

class DemoLaunchesTest extends TestCase
{
    use RefreshDatabase, UsesDemoScratch;

    public function test_the_map_draws_a_launch_broken_at_first_sign_in_and_one_scrubbed_on_the_pad(): void
    {
        $owner = User::factory()->owner()->create();

        $this->artisan('tests:demo')->assertSuccessful();

        [$scrubbed, $failed] = Launch::query()->orderBy('id')->get()->all();
        $this->assertSame(Launch::SCRUBBED, $scrubbed->outcome);
        $this->assertSame(Launch::FAILED, $failed->outcome);

        $this->actingAs($owner)->get(route('simulation.journey-map'))
            ->assertOk()
            ->assertSee('data-test="outcome"', false)
            ->assertSeeInOrder(['Subscription', 'Provisioning', 'First sign-in'])
            ->assertSee(['This password reset token is invalid.', 'First sign-in failed after 2 of 5 steps.']);

        $stage = collect($failed->stages)->firstWhere('stage', 'first-sign-in');
        $this->assertFalse($stage['passed']);

        foreach ($stage['walks'][0]['steps'] as $step) {
            $this->actingAs($owner)->get(route('simulation.journey-map.shot', [$failed, 'first-sign-in', 0, $step['screenshot']]))
                ->assertOk()
                ->assertHeader('content-type', 'image/jpeg');
        }

        $this->actingAs($owner)->get(route('simulation.journey-map', ['launch' => $scrubbed->id]))
            ->assertOk()
            ->assertSee('Scrubbed on the pad: nothing was flown.')
            ->assertSee(['Stripe CLI signed in: run `stripe login` on this machine', 'sales answers: https://sales.demo.practice.test/up answered 502'])
            ->assertDontSee('website answers:');

        $this->artisan('tests:demo --wipe')->assertSuccessful();

        $this->assertSame(0, Launch::count());
        $this->assertDirectoryDoesNotExist(Demo::launchShots());
    }
}
