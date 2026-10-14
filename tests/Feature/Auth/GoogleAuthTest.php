<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

/**
 * Google OAuth callback phải giữ nguyên quy tắc hiện có sau khi email trở thành
 * tùy chọn khi đăng ký: tìm tài khoản theo email Google, liên kết google_id,
 * tạo tài khoản Google mới (email KHÔNG bị bỏ qua/thay thế), và chuyển tới
 * complete-profile khi chưa có username. Không gọi Google thật (dùng fake).
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_user_with_matching_email_is_linked_and_logged_in(): void
    {
        $user = User::factory()->create([
            'username' => 'guser',
            'email' => 'guser@example.com',
            'google_id' => null,
        ]);

        Socialite::fake('google', GoogleUser::fake([
            'id' => 'google-1',
            'name' => 'G User',
            'email' => 'guser@example.com',
            'avatar' => 'https://example.com/a.png',
        ]));

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));

        $user->refresh();
        $this->assertSame('google-1', $user->google_id);
        // Email cũ của tài khoản KHÔNG bị thay đổi.
        $this->assertSame('guser@example.com', $user->email);
    }

    public function test_existing_user_without_username_goes_to_complete_profile(): void
    {
        $user = User::factory()->create([
            'username' => null,
            'email' => 'newuser@example.com',
        ]);

        Socialite::fake('google', GoogleUser::fake([
            'id' => 'google-2',
            'email' => 'newuser@example.com',
        ]));

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('complete-profile.create'));
    }

    public function test_new_google_user_is_created_with_email_preserved(): void
    {
        Socialite::fake('google', GoogleUser::fake([
            'id' => 'google-3',
            'name' => 'Brand New',
            'email' => 'brandnew@example.com',
            'avatar' => 'https://example.com/b.png',
        ]));

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('complete-profile.create'));

        $user = User::where('email', 'brandnew@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('google-3', $user->google_id);
        $this->assertNull($user->username);
        // Email do Google cung cấp được giữ nguyên, không bị bỏ qua.
        $this->assertSame('brandnew@example.com', $user->email);
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_failure_redirects_to_login_with_error(): void
    {
        Socialite::fake('google', function () {
            throw new \Exception('oauth cancelled');
        });

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();
    }
}
