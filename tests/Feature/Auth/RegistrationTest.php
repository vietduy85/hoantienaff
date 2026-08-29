<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_username_with_hyphen_is_rejected(): void
    {
        $response = $this->post('/register', [
            'name' => 'An Tuyet',
            'username' => 'anhtuyet-82',
            'email' => 'anhtuyet@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('username');
        $response->assertSessionHasErrors(['username' => 'Username chỉ được chứa chữ cái, số và dấu gạch dưới (_).']);
        $this->assertGuest();
    }

    public function test_username_with_underscore_is_accepted(): void
    {
        $response = $this->post('/register', [
            'name' => 'An Tuyet',
            'username' => 'anhtuyet_82',
            'email' => 'anhtuyet@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['username' => 'anhtuyet_82', 'email' => 'anhtuyet@example.com']);
    }

    public function test_username_without_separators_is_accepted(): void
    {
        $response = $this->post('/register', [
            'name' => 'An Tuyet',
            'username' => 'anhtuyet82',
            'email' => 'anhtuyet@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['username' => 'anhtuyet82', 'email' => 'anhtuyet@example.com']);
    }

    public function test_uppercase_username_with_hyphen_fails_after_normalize(): void
    {
        $response = $this->post('/register', [
            'name' => 'An Tuyet',
            'username' => 'ANHTUYET-82',
            'email' => 'anhtuyet@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }
}
