<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Team\Index;
use App\Mail\UserInvitation as UserInvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_send_an_invitation(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('openInviteModal')
            ->set('inviteEmail', 'newperson@example.com')
            ->set('inviteRole', 'member')
            ->call('sendInvitation')
            ->assertDispatched('invitation-sent');

        $this->assertDatabaseHas('invitations', [
            'email' => 'newperson@example.com',
            'role' => 'member',
            'invited_by' => $owner->id,
        ]);

        Mail::assertSent(UserInvitationMail::class, fn ($mail) => $mail->hasTo('newperson@example.com'));
    }

    public function test_invitation_with_overrides_persists_them(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('openInviteModal')
            ->set('inviteEmail', 'override@example.com')
            ->set('inviteRole', 'member')
            ->set('inviteOverrides.billing', 'full')
            ->set('inviteOverrides.dashboard', 'inherit')
            ->call('sendInvitation');

        $invitation = Invitation::where('email', 'override@example.com')->firstOrFail();

        $this->assertSame(['billing' => 'full'], $invitation->overrides);
    }

    public function test_invitation_rejects_email_of_existing_user(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->set('inviteEmail', 'taken@example.com')
            ->set('inviteRole', 'member')
            ->call('sendInvitation')
            ->assertHasErrors(['inviteEmail']);

        $this->assertDatabaseMissing('invitations', ['email' => 'taken@example.com']);
        Mail::assertNothingSent();
    }

    public function test_resend_regenerates_token_and_resends_email(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $invitation = Invitation::factory()->create(['invited_by' => $owner->id]);
        $oldToken = $invitation->token;

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('resendInvitation', $invitation->id)
            ->assertDispatched('invitation-resent');

        $this->assertNotSame($oldToken, $invitation->fresh()->token);
        Mail::assertSent(UserInvitationMail::class);
    }

    public function test_cancel_deletes_a_pending_invitation(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $invitation = Invitation::factory()->create(['invited_by' => $owner->id]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('cancelInvitation', $invitation->id)
            ->assertDispatched('invitation-cancelled');

        $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
    }

    public function test_cancel_does_not_delete_accepted_invitations(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $invitation = Invitation::factory()->accepted()->create(['invited_by' => $owner->id]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('cancelInvitation', $invitation->id);

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id]);
    }

    public function test_accept_invitation_creates_user_and_logs_in(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $invitation = Invitation::factory()->create([
            'email' => 'newhire@example.com',
            'role' => UserRole::Admin,
            'invited_by' => $owner->id,
        ]);

        $response = $this->get(route('invitations.accept', ['token' => $invitation->token]));
        $response->assertOk();

        $this->post(route('invitations.accept', ['token' => $invitation->token]));

        Volt::test('pages.invitations.accept', ['token' => $invitation->token])
            ->set('name', 'New Hire')
            ->set('password', 'secret-password')
            ->set('password_confirmation', 'secret-password')
            ->call('accept')
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('users', [
            'email' => 'newhire@example.com',
            'name' => 'New Hire',
            'role' => 'admin',
        ]);

        $this->assertNotNull($invitation->fresh()->accepted_at);

        $user = User::where('email', 'newhire@example.com')->first();
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_accept_invitation_applies_overrides(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $invitation = Invitation::factory()->create([
            'email' => 'override@example.com',
            'role' => UserRole::Member,
            'overrides' => ['billing' => 'full'],
            'invited_by' => $owner->id,
        ]);

        Volt::test('pages.invitations.accept', ['token' => $invitation->token])
            ->set('name', 'Override Person')
            ->set('password', 'secret-password')
            ->set('password_confirmation', 'secret-password')
            ->call('accept');

        $user = User::where('email', 'override@example.com')->firstOrFail();

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $user->id,
            'permission_key' => 'billing',
            'access_level' => 'full',
        ]);
    }

    public function test_expired_invitation_shows_expired_status(): void
    {
        $invitation = Invitation::factory()->expired()->create();

        Volt::test('pages.invitations.accept', ['token' => $invitation->token])
            ->assertSet('status', 'expired');
    }

    public function test_already_accepted_invitation_shows_accepted_status(): void
    {
        $invitation = Invitation::factory()->accepted()->create();

        Volt::test('pages.invitations.accept', ['token' => $invitation->token])
            ->assertSet('status', 'accepted');
    }

    public function test_invalid_token_shows_invalid_status(): void
    {
        Volt::test('pages.invitations.accept', ['token' => 'nonexistent-token'])
            ->assertSet('status', 'invalid');
    }

    public function test_email_taken_when_user_signed_up_separately(): void
    {
        $invitation = Invitation::factory()->create(['email' => 'duplicate@example.com']);
        User::factory()->create(['email' => 'duplicate@example.com']);

        Volt::test('pages.invitations.accept', ['token' => $invitation->token])
            ->assertSet('status', 'email_taken');
    }
}
