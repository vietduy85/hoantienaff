<?php

namespace Tests\Unit\Services\Lazada;

use App\Models\User;
use App\Services\Lazada\LazadaUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixture\LazadaConversionFixture;
use Tests\TestCase;

class LazadaUserResolverTest extends TestCase
{
    use RefreshDatabase;

    private LazadaUserResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new LazadaUserResolver();
    }

    public function test_sub_id1_user_id_with_matching_sub_id2_username_resolves(): void
    {
        $user = User::factory()->create(['username' => 'alice123']);
        $record = LazadaConversionFixture::record([
            'subId1' => (string) $user->id,
            'subId2' => 'alice123',
        ]);

        $resolved = $this->resolver->resolveWithDetail($record);

        $this->assertSame($user->id, $resolved['user_id']);
        $this->assertSame('alice123', $resolved['username']);
        $this->assertSame('sub_id1', $resolved['matched_by']);
    }

    public function test_sub_id1_user_id_without_sub_id2_still_resolves(): void
    {
        $user = User::factory()->create(['username' => 'alice123']);
        $record = LazadaConversionFixture::record([
            'subId1' => (string) $user->id,
            'subId2' => '',
        ]);

        $resolved = $this->resolver->resolveWithDetail($record);

        $this->assertSame($user->id, $resolved['user_id']);
        $this->assertSame('sub_id1_only', $resolved['matched_by']);
    }

    public function test_sub_id2_mismatch_is_unresolved(): void
    {
        User::factory()->create(['username' => 'alice123']);
        $record = LazadaConversionFixture::record([
            'subId1' => '1',
            'subId2' => 'mallory999',
        ]);

        $resolved = $this->resolver->resolveWithDetail($record);

        $this->assertNull($resolved['user_id']);
        $this->assertNull($resolved['username']);
        $this->assertSame('subid2_mismatch', $resolved['matched_by']);
    }

    public function test_unknown_user_id_is_unresolved(): void
    {
        $record = LazadaConversionFixture::record([
            'subId1' => '999999',
            'subId2' => 'ghost',
        ]);

        $resolved = $this->resolver->resolveWithDetail($record);

        $this->assertNull($resolved['user_id']);
        $this->assertSame('sub_id1_unknown', $resolved['matched_by']);
    }

    public function test_non_numeric_or_missing_sub_id1_is_unresolved(): void
    {
        $record = LazadaConversionFixture::record([
            'subId1' => 'not-a-number',
            'subId2' => '',
        ]);

        $resolved = $this->resolver->resolveWithDetail($record);

        $this->assertNull($resolved['user_id']);
        $this->assertSame('sub_id1_missing', $resolved['matched_by']);
    }

    public function test_no_sub_ids_is_unresolved(): void
    {
        $record = LazadaConversionFixture::record([
            'subId1' => '',
            'subId2' => '',
        ]);

        $resolved = $this->resolver->resolveWithDetail($record);

        $this->assertNull($resolved['user_id']);
        $this->assertSame('no_subid', $resolved['matched_by']);
    }

    public function test_username_is_never_used_as_lookup_key(): void
    {
        $user = User::factory()->create(['username' => 'alice123']);

        // Even if someone passes the username in subId1, numeric id is required.
        $record = LazadaConversionFixture::record([
            'subId1' => 'alice123',
            'subId2' => 'alice123',
        ]);

        $resolved = $this->resolver->resolveWithDetail($record);

        $this->assertNull($resolved['user_id']);
        $this->assertNotSame($user->id, $resolved['user_id']);
    }
}