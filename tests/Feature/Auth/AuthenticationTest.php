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

    public function test_user_without_email_can_authenticate_using_username(): void
    {
        $user = User::factory()->create([
            'username' => 'noemailuser',
            'email' => null,
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'login' => 'noemailuser',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_multiple_users_without_email_authenticate_independently(): void
    {
        $first = User::factory()->create([
            'username' => 'firstuser',
            'email' => null,
            'password' => Hash::make('first-password'),
        ]);

        $second = User::factory()->create([
            'username' => 'seconduser',
            'email' => null,
            'password' => Hash::make('second-password'),
        ]);

        // Đăng nhập tài khoản KHÔNG email thứ hai bằng username phải xác thực
        // đúng người thứ hai, không bị khớp nhầm sang dòng email NULL đầu tiên.
        $this->post('/login', [
            'login' => 'seconduser',
            'password' => 'second-password',
        ]);

        $this->assertAuthenticatedAs($second);
    }

    public function test_user_with_email_can_authenticate_using_both_username_and_email(): void
    {
        $user = User::factory()->create([
            'username' => 'withmail',
            'email' => 'withmail@example.com',
            'password' => Hash::make('password'),
        ]);

        // Bằng username.
        $this->post('/login', ['login' => 'withmail', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        // Bằng email.
        $this->post('/login', ['login' => 'withmail@example.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_remember_me_sets_remember_token(): void
    {
        $user = User::factory()->create([
            'username' => 'rememberme',
            'password' => Hash::make('password'),
            'remember_token' => null,
        ]);

        $this->post('/login', [
            'login' => 'rememberme',
            'password' => 'password',
            'remember' => '1',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_login_redirects_to_intended_url(): void
    {
        $user = User::factory()->create([
            'username' => 'intendeduser',
            'password' => Hash::make('password'),
        ]);

        $this->get('/dashboard'); // ghi intended = /dashboard

        $response = $this->post('/login', [
            'login' => 'intendeduser',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/dashboard');
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $user = User::factory()->create([
            'username' => 'throttleduser',
            'password' => Hash::make('password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'throttleduser', 'password' => 'wrong-password']);
        }

        // Lần thứ 6 với MẬT KHẨU ĐÚNG vẫn bị chặn ⇒ throttling còn nguyên.
        $this->post('/login', ['login' => 'throttleduser', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_login_page_uses_neutral_theme_color_and_no_orange_gradient(): void
    {
        config(['pwa.enabled' => true]);

        $response = $this->get('/');

        $response->assertOk();
        // Trang đăng nhập ghi đè theme-color sang màu trung tinh (không còn cam).
        $response->assertSee('<meta name="theme-color" content="#F7F8FA">', false);
        $response->assertDontSee('content="#FF6A00"', false);
        // Không còn nền gradient cam nhạt bao quanh card.
        $response->assertDontSee('#FFEDE3', false);
        $response->assertDontSee('#FFF6F1', false);
        // Vẫn giữ viewport-fit=cover cho safe-area.
        $response->assertSee('viewport-fit=cover', false);
    }
}
