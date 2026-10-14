<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_is_served_from_home_for_guests(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('auth.login');
    }

    public function test_legacy_login_get_redirects_to_home(): void
    {
        $response = $this->get('/login');

        $response->assertRedirect('/');
    }

    public function test_home_renders_login_ui_with_username_email_and_google(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('auth.login');
        $response->assertSee('Bạn chưa có tài khoản?');
        $response->assertSee('Nhập Username/Email');
        $response->assertSee('Nhập mật khẩu');
        $response->assertSee('Quên mật khẩu?');
        $response->assertSee('Hoặc đăng nhập bằng Google');
        // Post tới backend login hiện có; không tạo luồng xác thực mới.
        $response->assertSee('action="'.route('login').'"', false);
        // Tái sử dụng route Google OAuth hiện có + route đăng ký hiện có.
        $response->assertSee(route('google.redirect'), false);
        $response->assertSee(route('register'), false);
    }

    public function test_authenticated_user_visiting_login_does_not_see_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_users_can_authenticate_using_email(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_authenticate_using_username(): void
    {
        $user = User::factory()->create([
            'username' => 'txthinh',
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'login' => 'txthinh',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_nonexistent_username(): void
    {
        $this->post('/login', [
            'login' => 'nonexistentuser',
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_nonexistent_email(): void
    {
        $this->post('/login', [
            'login' => 'nonexistent@example.com',
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
