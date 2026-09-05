<?php

namespace Tests\Unit\Services\ShopeeFood;

use App\Models\User;
use App\Services\ShopeeFood\DTOs\ShopeeFoodCheckout;
use App\Services\ShopeeFood\ShopeeFoodUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeFoodUserResolverTest extends TestCase
{
    use RefreshDatabase;

    private function checkout(
        ?string $utmContent = null,
        ?string $format = null,
        string $subId1 = '',
        ?string $contentId = null,
    ): ShopeeFoodCheckout {
        return new ShopeeFoodCheckout(
            checkoutId: 'C1',
            conversionStatus: 2,
            checkoutCap: null,
            isShopeeCapped: false,
            cappedCommission: null,
            affiliateNetCommission: null,
            utmContent: $utmContent,
            utmFormat: $format,
            subId1: $subId1,
            subId2: '',
            subId3: '',
            subId4: '',
            subId5: '',
            contentId: $contentId,
            clickedAt: null,
            purchasedAt: null,
            completedAt: null,
            orders: [],
            raw: [],
        );
    }

    public function test_format_a_resolves_by_username(): void
    {
        User::factory()->create(['username' => 'alice123']);

        [$userId, $username] = (new ShopeeFoodUserResolver())->resolve(
            $this->checkout(format: 'A', subId1: 'alice123')
        );

        $this->assertNotNull($userId);
        $this->assertSame('alice123', $username);
    }

    public function test_format_a_with_empty_sub_id1_is_unresolved(): void
    {
        $detail = (new ShopeeFoodUserResolver())->resolveWithDetail(
            $this->checkout(format: 'A', subId1: '')
        );

        $this->assertNull($detail['user_id']);
        $this->assertNull($detail['username']);
        $this->assertSame('format_a_empty', $detail['matched_by']);
    }

    public function test_format_a_unknown_username_is_unresolved_not_guessed(): void
    {
        $detail = (new ShopeeFoodUserResolver())->resolveWithDetail(
            $this->checkout(format: 'A', subId1: 'nobody-here')
        );

        $this->assertNull($detail['user_id']);
        $this->assertNull($detail['username']);
        $this->assertSame('sub_id1_unknown', $detail['matched_by']);
    }

    public function test_format_b_resolves_by_content_id_without_guessing(): void
    {
        User::factory()->create(['username' => 'share-content-77']);

        $detail = (new ShopeeFoodUserResolver())->resolveWithDetail(
            $this->checkout(format: 'B', contentId: 'share-content-77')
        );

        $this->assertNotNull($detail['user_id']);
        $this->assertSame('share-content-77', $detail['username']);
        $this->assertSame('content_id', $detail['matched_by']);
    }

    public function test_format_b_unmatched_share_id_is_unresolved_never_guessed(): void
    {
        // shareId is NOT a username: even though sub_id1 could look like one,
        // FORMAT B must NOT resolve via sub_id1 and must NOT transform it.
        $detail = (new ShopeeFoodUserResolver())->resolveWithDetail(
            $this->checkout(
                format: 'B',
                subId1: 'some-share-uid',
                contentId: 'unknown-content-9',
            )
        );

        $this->assertNull($detail['user_id']);
        $this->assertNull($detail['username']);
        $this->assertSame('content_id_unknown', $detail['matched_by']);
    }

    public function test_format_b_empty_content_id_is_unresolved(): void
    {
        $detail = (new ShopeeFoodUserResolver())->resolveWithDetail(
            $this->checkout(format: 'B', contentId: null)
        );

        $this->assertNull($detail['user_id']);
        $this->assertSame('format_b_empty', $detail['matched_by']);
    }

    public function test_no_utm_is_unresolved(): void
    {
        $detail = (new ShopeeFoodUserResolver())->resolveWithDetail(
            $this->checkout(format: null)
        );

        $this->assertNull($detail['user_id']);
        $this->assertSame('no_utm', $detail['matched_by']);
    }

    public function test_never_falls_back_to_default_account(): void
    {
        $this->assertSame(
            0,
            User::where('username', 'tintuctonghop103')->count(),
            'precondition: default fallback account must NOT exist for the resolver to prove it never uses it',
        );

        $detail = (new ShopeeFoodUserResolver())->resolveWithDetail(
            $this->checkout(format: 'A', subId1: 'ghost')
        );

        $this->assertNull($detail['user_id']);
        $this->assertNull($detail['username']);
    }
}