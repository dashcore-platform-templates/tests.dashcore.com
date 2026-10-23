<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Dashcore\Bridge\Contracts\ResolvesSsoUser;
use Dashcore\Bridge\Sso\SsoIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * fleets hands a new client fleet's tests the admins from the client's brief,
 * so the owner and admins have an account before they first sign in through
 * login — which never makes one.
 */
class ImportClientBriefTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $brief
     */
    private function brief(array $brief): string
    {
        return base64_encode((string) json_encode(['format' => 'dashcore.client-brief/1', ...$brief]));
    }

    public function test_the_owner_becomes_owner_and_the_other_admins_admins_verified_and_nobody_is_emailed(): void
    {
        Notification::fake();
        Mail::fake();

        $this->artisan('brief:import', ['--base64' => $this->brief([
            'admins' => [
                ['email' => 'maria.lopez@dashcore.com'],
                ['name' => 'Sarah Practice', 'email' => ' Sarah@Dashcore.com ', 'owner' => true],
                ['name' => 'No Email'],
                ['email' => 'not-an-address'],
            ],
        ])])->expectsOutput('Added: sarah@dashcore.com (owner), maria.lopez@dashcore.com (admin).')
            ->doesntExpectOutputToContain('password')
            ->assertSuccessful();

        $this->assertSame(['sarah@dashcore.com', 'maria.lopez@dashcore.com'], User::query()->orderBy('id')->pluck('email')->all());

        $sarah = User::query()->where('email', 'sarah@dashcore.com')->sole();
        $this->assertSame('Sarah Practice', $sarah->name);
        $this->assertSame(UserRole::Owner, $sarah->role);
        $this->assertNotNull($sarah->email_verified_at);
        $this->assertTrue($sarah->hasVerifiedEmail());

        $maria = User::query()->where('email', 'maria.lopez@dashcore.com')->sole();
        $this->assertSame('Maria Lopez', $maria->name);
        $this->assertSame(UserRole::Admin, $maria->role);
        $this->assertNotNull($maria->email_verified_at);

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_without_an_owner_flag_the_first_admin_is_the_owner(): void
    {
        $this->artisan('brief:import', ['--base64' => $this->brief([
            'admins' => [['email' => 'sarah@dashcore.com'], ['email' => 'james@dashcore.com']],
        ])])->assertSuccessful();

        $this->assertSame(UserRole::Owner, User::query()->where('email', 'sarah@dashcore.com')->sole()->role);
        $this->assertSame(UserRole::Admin, User::query()->where('email', 'james@dashcore.com')->sole()->role);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $brief = $this->brief(['admins' => [
            ['name' => 'Sarah', 'email' => 'sarah@dashcore.com', 'owner' => true],
            ['name' => 'James', 'email' => 'james@dashcore.com'],
        ]]);

        $this->artisan('brief:import', ['--base64' => $brief])->assertSuccessful();
        $before = User::query()->orderBy('id')->get(['id', 'password', 'email_verified_at', 'role'])->toArray();

        $this->artisan('brief:import', ['--base64' => $brief])
            ->expectsOutput('Already in: sarah@dashcore.com (owner), james@dashcore.com (admin).')
            ->assertSuccessful();

        $this->assertSame(2, User::query()->count());
        $this->assertEquals($before, User::query()->orderBy('id')->get(['id', 'password', 'email_verified_at', 'role'])->toArray());
    }

    public function test_an_existing_account_keeps_its_password_and_is_raised_whatever_the_case(): void
    {
        $sarah = User::factory()->create(['email' => 'Sarah@Dashcore.com', 'password' => 'her-own-password']);
        $james = User::factory()->finance()->create(['email' => 'james@dashcore.com', 'password' => 'james-password']);

        $this->artisan('brief:import', ['--base64' => $this->brief([
            'admins' => [['email' => 'sarah@dashcore.com', 'owner' => true], ['email' => 'james@dashcore.com']],
        ])])->expectsOutput('Raised: sarah@dashcore.com (owner), james@dashcore.com (admin).')
            ->assertSuccessful();

        $this->assertSame(2, User::query()->count());
        $this->assertSame(UserRole::Owner, $sarah->fresh()->role);
        $this->assertSame(UserRole::Admin, $james->fresh()->role);
        $this->assertTrue(Hash::check('her-own-password', $sarah->fresh()->password));
        $this->assertTrue(Hash::check('james-password', $james->fresh()->password));
    }

    public function test_an_owner_is_never_made_an_admin_and_people_off_the_brief_are_untouched(): void
    {
        $james = User::factory()->owner()->create(['email' => 'james@dashcore.com']);
        $other = User::factory()->create(['email' => 'other@dashcore.com']);

        $this->artisan('brief:import', ['--base64' => $this->brief([
            'admins' => [['email' => 'sarah@dashcore.com', 'owner' => true], ['email' => 'james@dashcore.com']],
        ])])->expectsOutput('Added: sarah@dashcore.com (owner). Already in: james@dashcore.com (owner).')
            ->assertSuccessful();

        $this->assertSame(UserRole::Owner, $james->fresh()->role);
        $this->assertSame(UserRole::Member, $other->fresh()->role);
    }

    public function test_a_created_owner_can_sign_in_through_login(): void
    {
        $this->artisan('brief:import', ['--base64' => $this->brief([
            'admins' => [['email' => 'sarah@dashcore.com', 'owner' => true]],
        ])])->assertSuccessful();

        $identity = new SsoIdentity(subject: 'sub-sarah', email: 'sarah@dashcore.com', emailVerified: true, name: 'Sarah', sessionId: null, claims: []);
        $resolved = app(ResolvesSsoUser::class)->resolve($identity);

        $this->assertTrue($resolved?->is(User::query()->where('email', 'sarah@dashcore.com')->sole()));
        $this->assertTrue($resolved->isOwner());
    }

    public function test_a_brief_with_no_admins_is_reported_and_nothing_is_made(): void
    {
        $this->artisan('brief:import', ['--base64' => $this->brief(['admins' => []])])
            ->expectsOutput('Nothing for tests in this brief.')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_reads_a_brief_from_a_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'brief');
        file_put_contents($path, base64_decode($this->brief(['admins' => [['email' => 'sarah@dashcore.com']]])));

        try {
            $this->artisan('brief:import', ['--file' => $path])->assertSuccessful();
        } finally {
            unlink($path);
        }

        $this->assertSame(UserRole::Owner, User::query()->sole()->role);
    }

    public function test_it_refuses_anything_that_is_not_a_client_brief(): void
    {
        $this->artisan('brief:import')
            ->expectsOutput('Give a readable client brief with --file or --base64.')
            ->assertFailed();

        $this->artisan('brief:import', ['--base64' => base64_encode('{"format":"something-else"}')])
            ->expectsOutput('Not a client brief (expected format dashcore.client-brief/1).')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
