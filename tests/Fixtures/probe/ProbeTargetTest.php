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

    public function test_opens_the_invite_modal(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Index::class)->call('openInviteModal')->assertSet('showInviteModal', true);
    }

    public function test_asserts_nothing(): void
    {
        User::factory()->make()->isOwner();
    }

    public function test_fails(): void
    {
        $this->assertSame(1, 2, 'deliberately failing');
    }
}
