<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chính sách truy cập dashboard của tài khoản không email.
 *
 * App\Models\User kế thừa trait Illuminate\Auth\MustVerifyEmail (qua
 * Illuminate\Foundation\Auth\User) nhưng KHÔNG implement contract
 * Illuminate\Contracts\Auth\MustVerifyEmail. Middleware alias `verified`
 * (EnsureEmailIsVerified) thoát sớm khi user không phải instance của contract,
 * nên thực tế KHÔNG chặn ai. Vì vậy tài khoản không email vào dashboard được
 * mà không cần đánh dấu email giả / gán email_verified_at.
 */
class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_email_can_access_dashboard(): void
    {
        $user = User::factory()->create(['email' => null, 'email_verified_at' => null]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
    }

    public function test_user_with_unverified_email_can_access_dashboard_current_policy(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        // Ghi nhận chính sách hiện tại: middleware `verified` không chặn vì User
        // không implement contract MustVerifyEmail (email verification đang không
        // được enforce). Test này khóa hành vi để thay đổi tương lai không âm thầm.
        $response->assertOk();
    }

    public function test_user_model_does_not_implement_must_verify_email_contract(): void
    {
        $user = User::factory()->make();

        $this->assertFalse($user instanceof MustVerifyEmail);
    }

    public function test_guest_is_redirected_from_dashboard(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }
}
