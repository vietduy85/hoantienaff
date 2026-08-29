<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersGenerateUsernameTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_strips_hyphen_from_email_when_generating_username(): void
    {
        User::factory()->create([
            'username' => null,
            'email' => 'anhtuyet-82@gmail.com',
        ]);

        $this->artisan('users:generate-username')->assertExitCode(0);

        $user = User::where('email', 'anhtuyet-82@gmail.com')->first();
        $this->assertNotNull($user->username);
        $this->assertSame('anhtuyet82', $user->username);
        $this->assertStringNotContainsString('-', $user->username);
    }

    public function test_command_strips_multiple_hyphens_from_email(): void
    {
        User::factory()->create([
            'username' => null,
            'email' => 'abc-def-123@gmail.com',
        ]);

        $this->artisan('users:generate-username')->assertExitCode(0);

        $user = User::where('email', 'abc-def-123@gmail.com')->first();
        $this->assertSame('abcdef123', $user->username);
    }

    public function test_command_appends_number_when_normalized_username_exists(): void
    {
        User::factory()->create(['username' => 'hangngo070787']);

        User::factory()->create([
            'username' => null,
            'email' => 'hang-ngo070787@gmail.com',
        ]);

        $this->artisan('users:generate-username')->assertExitCode(0);

        $user = User::where('email', 'hang-ngo070787@gmail.com')->first();
        $this->assertSame('hangngo0707872', $user->username);
    }
}
