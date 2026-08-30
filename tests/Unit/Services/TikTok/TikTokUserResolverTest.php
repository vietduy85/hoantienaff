<?php

namespace Tests\Unit\Services\TikTok;

use App\Models\LinkRequest;
use App\Models\User;
use App\Services\TikTok\DTOs\TikTokOrder;
use App\Services\TikTok\TikTokUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TikTokUserResolverTest extends TestCase
{
    use RefreshDatabase;

    private User $fallback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fallback = User::factory()->create([
            'username' => 'tintuctonghop103',
        ]);
    }

    private function order(array $overrides = []): TikTokOrder
    {
        return TikTokOrder::fromArray(array_merge([
            'order_id'   => 'ORD-1',
            'product_id' => 'P-1',
        ], $overrides));
    }

    public function test_empty_sub_id_falls_back_to_default_account(): void
    {
        [$id, $username] = (new TikTokUserResolver())->resolve($this->order());

        $this->assertSame($this->fallback->id, $id);
        $this->assertSame('tintuctonghop103', $username);
    }

    public function test_empty_sub1_falls_back_to_default_account(): void
    {
        [$id, $username] = (new TikTokUserResolver())->resolve(
            $this->order(['sub_id' => '', 'sub1' => ''])
        );

        $this->assertSame($this->fallback->id, $id);
        $this->assertSame('tintuctonghop103', $username);
    }

    public function test_sub_id_matching_username_resolves_directly(): void
    {
        $creator = User::factory()->create(['username' => 'creator_123']);

        [$id, $username] = (new TikTokUserResolver())->resolve(
            $this->order(['sub_id' => 'creator_123'])
        );

        $this->assertSame($creator->id, $id);
        $this->assertSame('creator_123', $username);
    }

    public function test_sub1_matching_username_resolves_directly(): void
    {
        $creator = User::factory()->create(['username' => 'creator_456']);

        [$id, $username] = (new TikTokUserResolver())->resolve(
            $this->order(['sub_id' => '', 'sub1' => 'creator_456'])
        );

        $this->assertSame($creator->id, $id);
        $this->assertSame('creator_456', $username);
    }

    public function test_unresolvable_sub_id_falls_back(): void
    {
        [$id, $username] = (new TikTokUserResolver())->resolve(
            $this->order(['sub_id' => 'no_such_user'])
        );

        $this->assertSame($this->fallback->id, $id);
        $this->assertSame('tintuctonghop103', $username);
    }

    public function test_item_match_alone_does_not_override_fallback(): void
    {
        $owner = User::factory()->create(['username' => 'owner_user']);
        LinkRequest::create([
            'user_id'       => $owner->id,
            'platform'      => 'TikTok Shop',
            'original_url'  => 'https://www.tiktok.com/@shop/1',
            'item_id'       => 1001,
            'status'        => 'completed',
        ]);

        // sub_id IS present and resolves to a real user -> that user wins,
        // regardless of any item match.
        [$id, $username] = (new TikTokUserResolver())->resolve(
            $this->order(['sub_id' => 'owner_user', 'product_id' => 1001])
        );
        $this->assertSame($owner->id, $id);
        $this->assertSame('owner_user', $username);

        // sub_id empty -> fallback, even though product_id maps to owner_user.
        [$id2, $username2] = (new TikTokUserResolver())->resolve(
            $this->order(['sub_id' => '', 'product_id' => 1001])
        );
        $this->assertSame($this->fallback->id, $id2);
        $this->assertSame('tintuctonghop103', $username2);
    }
}