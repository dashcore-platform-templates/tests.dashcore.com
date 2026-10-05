<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * This app is internal: people get in by invitation, never by signing up.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_register_route(): void
    {
        $this->assertFalse(Route::has('register'));

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Outsider',
            'email' => 'outsider@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'outsider@example.com']);
    }

    public function test_no_page_links_to_sign_up(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Sign up');
        $this->get('/')->assertDontSee('/register');
    }
}
