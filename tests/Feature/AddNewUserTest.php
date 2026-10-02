<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Team\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AddNewUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_new_user(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('openAddUserModal')
            ->set('newName', 'New Person')
            ->set('newEmail', 'new@example.com')
            ->set('newPassword', 'secret-pw')
            ->set('newRole', 'member')
            ->call('addUser')
            ->assertDispatched('user-added');

        $this->assertDatabaseHas('users', [
            'email' => 'new@example.com',
            'name' => 'New Person',
            'role' => 'member',
        ]);
    }

    public function test_adding_user_with_overrides_persists_permissions(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('openAddUserModal')
            ->set('newName', 'Override Person')
            ->set('newEmail', 'override@example.com')
            ->set('newPassword', 'secret-pw')
            ->set('newRole', 'member')
            ->set('newOverrides.billing', 'full')
            ->set('newOverrides.dashboard', 'inherit')
            ->call('addUser');

        $user = User::where('email', 'override@example.com')->firstOrFail();

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $user->id,
            'permission_key' => 'billing',
            'access_level' => 'full',
        ]);

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $user->id,
            'permission_key' => 'dashboard',
        ]);
    }

    public function test_validation_rejects_duplicate_email(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->set('newName', 'Dup')
            ->set('newEmail', 'taken@example.com')
            ->set('newPassword', 'secret-pw')
            ->set('newRole', 'member')
            ->call('addUser')
            ->assertHasErrors(['newEmail']);
    }

    public function test_validation_rejects_short_password(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->set('newName', 'Short Pw')
            ->set('newEmail', 'short@example.com')
            ->set('newPassword', 'short')
            ->set('newRole', 'member')
            ->call('addUser')
            ->assertHasErrors(['newPassword']);
    }
}
