<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakeAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_account_and_promotes_to_owner(): void
    {
        $this->artisan('app:make-admin', [
            'email' => 'admin@example.com',
            '--create' => true,
            '--name' => 'Admin Person',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->sole();

        $this->assertSame(UserRole::Owner, $user->role);
        $this->assertSame('Admin Person', $user->name);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_admin_dashboard_renders_for_the_created_account(): void
    {
        $this->artisan('app:make-admin', ['email' => 'admin@example.com', '--create' => true])
            ->assertSuccessful();

        $this->actingAs(User::query()->sole())
            ->get('/admin/dashboard')
            ->assertOk();
    }

    public function test_falls_back_to_admin_email_config(): void
    {
        config()->set('app.admin.email', 'admin@example.com');

        $this->artisan('app:make-admin', ['--create' => true])->assertSuccessful();

        $this->assertSame(UserRole::Owner, User::query()->sole()->role);
    }

    public function test_falls_back_to_admin_name_config(): void
    {
        config()->set('app.admin.email', 'admin@example.com');
        config()->set('app.admin.name', 'Config Admin');

        $this->artisan('app:make-admin', ['--create' => true])->assertSuccessful();

        $this->assertSame('Config Admin', User::query()->sole()->name);
    }

    public function test_derives_name_from_email_when_no_name_provided(): void
    {
        $this->artisan('app:make-admin', ['email' => 'travis@example.com', '--create' => true])
            ->assertSuccessful();

        $this->assertSame('Travis', User::query()->sole()->name);
    }

    public function test_fails_when_no_email_given_and_admin_email_unset(): void
    {
        $this->artisan('app:make-admin')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_refuses_to_create_without_create_flag(): void
    {
        $this->artisan('app:make-admin', ['email' => 'nobody@example.com'])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_promotes_existing_user_to_owner(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => UserRole::Member,
        ]);

        $this->artisan('app:make-admin', ['email' => 'admin@example.com', '--keep-password' => true])
            ->assertSuccessful();

        $this->assertSame(UserRole::Owner, $user->refresh()->role);
    }

    public function test_matches_email_case_insensitively(): void
    {
        $user = User::factory()->create([
            'email' => 'Admin@Example.com',
            'role' => UserRole::Member,
        ]);

        $this->artisan('app:make-admin', ['email' => 'ADMIN@EXAMPLE.COM', '--keep-password' => true])
            ->assertSuccessful();

        $this->assertSame(UserRole::Owner, $user->refresh()->role);
    }

    public function test_force_verifies_email_on_promotion(): void
    {
        User::factory()->unverified()->create(['email' => 'admin@example.com']);

        $this->artisan('app:make-admin', ['email' => 'admin@example.com', '--keep-password' => true])
            ->assertSuccessful();

        $this->assertNotNull(User::query()->sole()->email_verified_at);
    }

    public function test_refuses_weak_password_without_changing_anything(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $before = $user->password;

        $this->artisan('app:make-admin', ['email' => 'admin@example.com', '--password' => 'short'])
            ->assertFailed();

        $user->refresh();
        $this->assertSame($before, $user->password);
        $this->assertSame(UserRole::Member, $user->role);
    }

    public function test_leaves_password_alone_with_keep_password(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $before = $user->password;

        $this->artisan('app:make-admin', ['email' => 'admin@example.com', '--keep-password' => true])
            ->assertSuccessful();

        $this->assertSame($before, $user->refresh()->password);
    }

    public function test_rotates_password_on_rerun_by_default(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $before = $user->password;

        $this->artisan('app:make-admin', ['email' => 'admin@example.com'])
            ->assertSuccessful();

        $this->assertNotSame($before, $user->refresh()->password);
    }

    public function test_is_safe_to_run_twice(): void
    {
        $this->artisan('app:make-admin', ['email' => 'admin@example.com', '--create' => true])
            ->assertSuccessful();

        $this->artisan('app:make-admin', ['email' => 'admin@example.com', '--keep-password' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(UserRole::Owner, User::query()->sole()->role);
    }

    public function test_accepts_a_provided_password(): void
    {
        $this->artisan('app:make-admin', [
            'email' => 'admin@example.com',
            '--create' => true,
            '--password' => 'SecurePassword123!',
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('SecurePassword123!', User::query()->sole()->password));
    }

    public function test_refuses_weak_password_on_create(): void
    {
        $this->artisan('app:make-admin', [
            'email' => 'admin@example.com',
            '--create' => true,
            '--password' => 'short',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
