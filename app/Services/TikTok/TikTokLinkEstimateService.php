<?php

namespace App\Services\TikTok;

use App\Models\LinkRequest;
use App\Models\User;
use App\Services\CashbackCalculator;
use App\Services\TikTok\DTOs\TikTokProductDTO;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1 orchestration: TikTok product URL → RioHub link → product info →
 * estimated cashback → persist into link_requests.
 *
 * This NEVER touches orders, wallet or actual commission. It only builds the
 * link and an ESTIMATED cashback for display.
 */
class TikTokLinkEstimateService
{
    public function __construct(
        private readonly TikTokAffiliateService $affiliateService,
        private readonly TikTokProductService $productService,
        private readonly CashbackCalculator $cashbackCalculator,
    ) {}

    /**
     * Create the affiliate link and enrich the given LinkRequest with product
     * info + estimated cashback.
     *
     * Throws TikTokServiceException on a hard link-creation failure (so the
     * caller can mark the request failed / show an error). Product info is
     * best-effort: a failure there does not fail link creation.
     */
    public function create(LinkRequest $link, string $url, User $user): void
    {
        $linkDTO = $this->affiliateService->createAffiliateLink($url, $user->username);

        $affiliateUrl = $linkDTO->getAffiliateUrl();
        if (!$affiliateUrl) {
            throw new TikTokServiceException(
                '[TikTokLinkEstimate] RioHub returned empty affiliate_link',
                0,
                'Link TikTok Shop trống',
            );
        }

        $productId = $linkDTO->getProductId();

        $update = [
            'platform'     => 'TikTok Shop',
            'affiliate_url' => $affiliateUrl,
            'status'       => 'completed',
            'data_source'  => 'rioHub-api',
            'product_link' => $url,
        ];

        $product = null;

        if ($productId) {
            $update['item_id'] = (int) $productId;

            try {
                $product = $this->productService->getProduct($productId);
            } catch (TikTokServiceException $e) {
                Log::warning('[TikTokLinkEstimate] Product info failed (best-effort)', [
                    'product_id' => $productId,
                    'error'      => $e->getMessage(),
                ]);
                $product = null;
            }
        }

        if ($product !== null && $product->getProductId() !== '') {
            $this->applyEstimate($update, $product);
        }

        $link->update($update);
    }

    private function applyEstimate(array &$update, TikTokProductDTO $product): void
    {
        $name = $product->getName();
        if ($name !== null && $name !== '') {
            $update['product_name'] = $name;
        }

        $image = $product->getImageUrl();
        if ($image !== null) {
            $update['product_image'] = $image;
        }

        $shop = $product->getShopName();
        if ($shop !== null && $shop !== '') {
            $update['shop_name'] = $shop;
        }

        $price = $product->getPrice();
        $ratePct = $product->getEffectiveCommissionRatePct();

        if ($price !== null && $price > 0) {
            $update['product_price'] = (int) round($price);
        }

        if ($price > 0 && $ratePct !== null && $ratePct > 0) {
            // RioHub commission rates are basis points (e.g. 2300 = 23.00%),
            // so the gross estimated commission = price × rate ÷ 10000.
            // Reuse the same business thresholds/rounding as Shopee: tier
            // derived from ratio, then net = floor(commission × 0.90) × tier.
            $commissionAmount = floor($price * $ratePct / 10000);

            $cashback = $this->cashbackCalculator->calculate($commissionAmount, $price);

            $update['estimated_cashback']     = $commissionAmount;
            $update['user_estimated_cashback'] = $cashback['user_estimated_cashback'];
            $update['cashback_rate']           = $cashback['cashback_rate'];
        }
    }
}
