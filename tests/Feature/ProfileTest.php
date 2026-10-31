<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_profile_can_be_updated_without_email(): void
    {
        $user = User::factory()->create(['email' => null, 'email_verified_at' => null]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'No Email User',
                'email' => '',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertSame('No Email User', $user->name);
        $this->assertNull($user->email);
    }

    public function test_user_without_email_can_add_email_later(): void
    {
        $user = User::factory()->create(['email' => null, 'email_verified_at' => null]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'No Email User',
                'email' => 'added@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertSame('added@example.com', $user->email);
        // Không tự đánh dấu email mới là đã xác minh.
        $this->assertNull($user->email_verified_at);
    }

    public function test_profile_update_rejects_email_already_taken(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => null]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'No Email User',
                'email' => 'taken@example.com',
            ]);

        $response->assertSessionHasErrors('email');
        $this->assertNull($user->refresh()->email);
    }

    public function test_bank_form_only_updates_bank_fields(): void
    {
        $user = User::factory()->create(['name' => 'Giu Nguyen', 'email' => 'keep@example.com', 'phone' => '0900000000']);
        $bank = config('banks.list')[0];

        $this->actingAs($user)
            ->patch('/profile', [
                'section' => 'bank',
                'bank_name' => $bank,
                'bank_account_number' => '123456789',
                'bank_account_name' => 'nguyễn văn a',
                // Trường ngoài nhóm bị bỏ qua, kể cả khi bị gửi kèm.
                'name' => '',
                'email' => 'hacked@example.com',
                'phone' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'bank-updated')
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertSame('NGUYEN VAN A', $user->bank_account_name);
        $this->assertSame('123456789', $user->bank_account_number);
        $this->assertSame($bank, $user->bank_name);
        $this->assertSame('Giu Nguyen', $user->name);
        $this->assertSame('keep@example.com', $user->email);
        $this->assertSame('0900000000', $user->phone);
    }

    public function test_info_form_does_not_touch_bank_fields(): void
    {
        $user = User::factory()->create(['bank_account_number' => '999', 'bank_account_name' => 'TRAN B']);

        $this->actingAs($user)
            ->patch('/profile', [
                'section' => 'info',
                'name' => 'Ten Moi',
                'email' => '',
                'bank_account_number' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'profile-updated');

        $user->refresh();
        $this->assertSame('Ten Moi', $user->name);
        $this->assertSame('999', $user->bank_account_number);
        $this->assertSame('TRAN B', $user->bank_account_name);
    }

    public function test_profile_page_shows_bank_form_before_info(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/profile')->assertOk()->getContent();

        $bank = strpos($html, 'data-testid="bank-form"');
        $info = strpos($html, 'data-testid="info-card"');
        $this->assertNotFalse($bank);
        $this->assertTrue($bank < $info);
    }
}
