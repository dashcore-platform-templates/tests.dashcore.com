<?php

namespace Tests\Fixtures\Probe;

use App\Livewire\Team\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Not part of the suite: ProbeTest runs this file in a child process with the
 * probe attached and checks what the probe recorded for each test.
 */
class ProbeTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_visits_the_welcome_page(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_a_guest_is_turned_away(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_member_reaches_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
    }

    public function test_opens_the_invite_modal(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Index::class)->call('openInviteModal')->assertSet('showInviteModal', true);
    }

    public function test_asserts_nothing(): void
    {
        User::factory()->make()->isOwner();
    }

    public function test_sets_a_time_limit_like_code_under_test_can(): void
    {
        set_time_limit(330);

        $this->assertTrue(true);
    }

    public function test_a_time_limit_set_by_an_earlier_test_is_gone(): void
    {
        $this->assertSame('0', ini_get('max_execution_time'));
    }

    public function test_fails(): void
    {
        $this->assertSame(1, 2, 'deliberately failing');
    }
}
