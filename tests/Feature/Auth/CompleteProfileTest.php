<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompleteProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $completeProfileUrl = '/complete-profile';
    private string $checkUsernameUrl = '/check-username';

    public function test_user_without_username_can_view_form(): void
    {
        $user = User::factory()->create(['username' => null]);

        $response = $this
            ->actingAs($user)
            ->get($this->completeProfileUrl);

        $response->assertOk();
    }

    public function test_user_without_username_can_submit_username(): void
    {
        $user = User::factory()->create(['username' => null]);

        $response = $this
            ->actingAs($user)
            ->post($this->completeProfileUrl, [
                'username' => 'newuser123',
                'name' => 'New User',
            ]);

        $response->assertRedirect();
        $user->refresh();
        $this->assertSame('newuser123', $user->username);
    }

    public function test_user_with_username_is_redirected_on_get(): void
    {
        $user = User::factory()->create(['username' => 'existinguser']);

        $response = $this
            ->actingAs($user)
            ->get($this->completeProfileUrl);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('info');
    }

    public function test_user_with_username_cannot_change_username_via_post(): void
    {
        $user = User::factory()->create(['username' => 'existinguser']);

        $response = $this
            ->actingAs($user)
            ->post($this->completeProfileUrl, [
                'username' => 'newuser',
                'name' => 'Changed Name',
            ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('info');

        $user->refresh();
        $this->assertSame('existinguser', $user->username);
        $this->assertSame('existinguser', $user->getOriginal('username'));
    }

    public function test_user_with_username_cannot_change_via_check_username_api(): void
    {
        $user = User::factory()->create(['username' => 'existinguser']);

        $response = $this
            ->actingAs($user)
            ->getJson($this->checkUsernameUrl . '?username=someothername');

        $response->assertOk();
        $response->assertJson([
            'available' => false,
            'message' => 'Username đã được thiết lập và không thể thay đổi.',
        ]);
    }

    public function test_google_user_with_username_stays_unchanged_on_google_login(): void
    {
        $user = User::factory()->create([
            'username' => 'hangngo070787',
            'google_id' => 'google-123',
            'avatar' => 'https://example.com/avatar.jpg',
        ]);

        $this->actingAs($user)->get('/dashboard');
        $user->refresh();
        $this->assertSame('hangngo070787', $user->username);
    }

    public function test_suggestion_not_generated_for_existing_username(): void
    {
        $user = User::factory()->create(['username' => 'testuser']);

        $response = $this
            ->actingAs($user)
            ->get($this->completeProfileUrl);

        $response->assertRedirect();
    }

    public function test_user_without_username_gets_suggestion(): void
    {
        $user = User::factory()->create([
            'username' => null,
            'email' => 'testuser@example.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->get($this->completeProfileUrl);

        $response->assertOk();
    }

    public function test_suggestion_avoids_other_users_username(): void
    {
        User::factory()->create(['username' => 'testuser']);

        $user = User::factory()->create([
            'username' => null,
            'email' => 'testuser@example.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->post($this->completeProfileUrl, [
                'username' => 'testuser2',
                'name' => 'Test User',
            ]);

        $response->assertRedirect();
        $user->refresh();
        $this->assertSame('testuser2', $user->username);
    }

    public function test_unauthenticated_user_cannot_access_complete_profile(): void
    {
        $response = $this->get($this->completeProfileUrl);
        $response->assertRedirect('/login');
    }

    public function test_submitted_username_is_locked_after_first_set(): void
    {
        $user = User::factory()->create(['username' => null]);

        $this->actingAs($user)->post($this->completeProfileUrl, [
            'username' => 'lockedname',
            'name' => 'Locked Name',
        ]);

        $user->refresh();
        $this->assertSame('lockedname', $user->username);

        $this->actingAs($user)->post($this->completeProfileUrl, [
            'username' => 'hackedname',
            'name' => 'Hacked Name',
        ]);

        $user->refresh();
        $this->assertSame('lockedname', $user->username);
    }

    public function test_username_with_hyphen_is_rejected(): void
    {
        $user = User::factory()->create(['username' => null]);

        $response = $this
            ->actingAs($user)
            ->post($this->completeProfileUrl, [
                'username' => 'anhtuyet-82',
                'name' => 'An Tuyet',
            ]);

        $response->assertSessionHasErrors('username');
        $response->assertSessionHasErrors(['username' => 'Username chỉ được chứa chữ cái, số và dấu gạch dưới (_).']);
        $user->refresh();
        $this->assertNull($user->username);
    }

    public function test_username_with_underscore_is_accepted(): void
    {
        $user = User::factory()->create(['username' => null]);

        $response = $this
            ->actingAs($user)
            ->post($this->completeProfileUrl, [
                'username' => 'anhtuyet_82',
                'name' => 'An Tuyet',
            ]);

        $response->assertRedirect();
        $user->refresh();
        $this->assertSame('anhtuyet_82', $user->username);
    }

    public function test_username_without_separators_is_accepted(): void
    {
        $user = User::factory()->create(['username' => null]);

        $response = $this
            ->actingAs($user)
            ->post($this->completeProfileUrl, [
                'username' => 'anhtuyet82',
                'name' => 'An Tuyet',
            ]);

        $response->assertRedirect();
        $user->refresh();
        $this->assertSame('anhtuyet82', $user->username);
    }

    public function test_check_username_rejects_hyphen(): void
    {
        $user = User::factory()->create(['username' => null]);

        $response = $this
            ->actingAs($user)
            ->getJson($this->checkUsernameUrl . '?username=anhtuyet-82');

        $response->assertOk();
        $response->assertJson([
            'available' => false,
            'message' => 'Username chỉ được chứa chữ cái, số và dấu gạch dưới (_).',
        ]);
    }

    public function test_check_username_accepts_underscore(): void
    {
        $user = User::factory()->create(['username' => null]);

        $response = $this
            ->actingAs($user)
            ->getJson($this->checkUsernameUrl . '?username=anhtuyet_82');

        $response->assertOk();
        $response->assertJson([
            'available' => true,
        ]);
    }

    public function test_suggestion_from_email_with_hyphens_is_stripped(): void
    {
        $user = User::factory()->create([
            'username' => null,
            'email' => 'anhtuyet-82@gmail.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->get($this->completeProfileUrl);

        $response->assertOk();
        $response->assertViewHas('suggestion', 'anhtuyet82');
        $this->assertStringNotContainsString('-', $response->viewData('suggestion'));
    }

    public function test_suggestion_from_email_with_multiple_hyphens_is_stripped(): void
    {
        $user = User::factory()->create([
            'username' => null,
            'email' => 'abc-def-123@gmail.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->get($this->completeProfileUrl);

        $response->assertOk();
        $response->assertViewHas('suggestion', 'abcdef123');
    }

    public function test_suggestion_from_google_email_with_hyphen_is_stripped(): void
    {
        $user = User::factory()->create([
            'username' => null,
            'email' => 'hang-ngo070787@gmail.com',
            'google_id' => 'google-99',
        ]);

        $response = $this
            ->actingAs($user)
            ->get($this->completeProfileUrl);

        $response->assertOk();
        $response->assertViewHas('suggestion', 'hangngo070787');
    }
}
