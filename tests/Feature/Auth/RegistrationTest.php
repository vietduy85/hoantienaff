<?php

namespace Tests\Feature\Auth;

use App\Models\User;
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
            'username' => 'testuser',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('users', [
            'username' => 'testuser',
            'email' => 'test@example.com',
        ]);
    }

    public function test_users_can_register_without_email(): void
    {
        $response = $this->post('/register', [
            'username' => 'noemailuser',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('users', [
            'username' => 'noemailuser',
            'email' => null,
        ]);
        $this->assertNull(User::where('username', 'noemailuser')->first()->email);
    }

    public function test_empty_email_string_is_stored_as_null(): void
    {
        $response = $this->post('/register', [
            'username' => 'emptyemailuser',
            'email' => '',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertNull(User::where('username', 'emptyemailuser')->first()->email);
    }

    public function test_multiple_users_without_email_can_register(): void
    {
        User::factory()->create(['username' => 'existingnoemail', 'email' => null]);

        $response = $this->post('/register', [
            'username' => 'freshnoemail',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        // UNIQUE index trên email vẫn cho phép nhiều dòng NULL.
        $this->assertSame(2, User::whereNull('email')->count());
    }

    public function test_invalid_email_is_rejected_when_provided(): void
    {
        $response = $this->post('/register', [
            'username' => 'bademailuser',
            'email' => 'not-an-email',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->post('/register', [
            'username' => 'dupeemailuser',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_duplicate_username_is_rejected(): void
    {
        User::factory()->create(['username' => 'takenuser']);

        $response = $this->post('/register', [
            'username' => 'takenuser',
            'email' => 'another@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_password_confirmation_is_required(): void
    {
        $response = $this->post('/register', [
            'username' => 'mismatchuser',
            'password' => 'password',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
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

    public function test_registration_form_allows_optional_email(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $response->assertSee('Email không bắt buộc', false);

        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*name="email"[^>]*>/', $html);
        preg_match('/<input[^>]*name="email"[^>]*>/', $html, $matches);
        $this->assertStringNotContainsString('required', $matches[0]);
    }

    public function test_registration_with_eight_character_password_succeeds(): void
    {
        $response = $this->post('/register', [
            'username' => 'eightchar',
            'password' => 'abcdefgh',
            'password_confirmation' => 'abcdefgh',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_password_shorter_than_eight_is_rejected_with_vietnamese_message(): void
    {
        $response = $this->post('/register', [
            'username' => 'shortpass',
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
        ]);

        $response->assertSessionHasErrors(['password' => 'Mật khẩu phải có ít nhất 8 ký tự.']);
        $this->assertGuest();
    }

    public function test_simple_alphanumeric_password_is_accepted_without_special_or_uppercase(): void
    {
        $response = $this->post('/register', [
            'username' => 'simplepass',
            'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_password_confirmation_mismatch_shows_vietnamese_message(): void
    {
        $response = $this->post('/register', [
            'username' => 'mismatchmsg',
            'password' => 'abcdefgh',
            'password_confirmation' => 'different1',
        ]);

        $response->assertSessionHasErrors(['password' => 'Xác nhận mật khẩu không khớp.']);
        $this->assertGuest();
    }

    public function test_registration_form_shows_password_hint_and_no_raw_validation_key(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $response->assertSee('Mật khẩu cần ít nhất 8 ký tự.');
        $response->assertDontSee('validation.min.string');
    }

    public function test_short_password_error_page_shows_vietnamese_and_hides_hint(): void
    {
        $response = $this->followingRedirects()->post('/register', [
            'username' => 'shortfrm',
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
        ]);

        $response->assertOk();
        // Thông báo lỗi tiếng Việt thay cho mã kỹ thuật.
        $response->assertSee('Mật khẩu phải có ít nhất 8 ký tự.');
        $response->assertDontSee('validation.min.string');
        // Hướng dẫn bị thay thế bởi lỗi ⇒ không hiển thị trùng lặp.
        $response->assertDontSee('Mật khẩu cần ít nhất 8 ký tự.');
    }
}
