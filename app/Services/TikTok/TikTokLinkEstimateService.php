<?php

namespace App\Services\TikTok;

use App\Models\LinkRequest;
use App\Models\User;
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
        private readonly TikTokCashbackCalculator $cashbackCalculator,
    ) {}

    /**
     * Create the affiliate link and enrich the given LinkRequest with product
     * info + estimated cashback.
     *
     * Throws TikTokServiceException on a hard link-creation failure (so the
     * caller can mark the request failed / show an error). Product info is
     * best-effort: a failure there does not fail link creation.
     */
    public function create(LinkRequest $link, string $url, User $user, bool $fast = false, bool $deferProduct = false): void
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

        if ($productId) {
            $update['item_id'] = (int) $productId;
        }

        // Tác vụ 1: lưu affiliate link và hoàn tất NGAY.
        $link->update($update);

        // Tác vụ 2 (độc lập): thông tin sản phẩm + ước tính hoàn tiền chạy nền sau response.
        // Chế độ nhanh: bỏ hẳn. Lỗi ở đây không bao giờ ảnh hưởng link đã tạo.
        if ($fast || ! $productId) {
            return;
        }

        if (! $deferProduct) {
            $extra = $this->productUpdate((string) $productId);
            if ($extra !== []) {
                $link->update($extra);
            }

            return;
        }

        $linkId = $link->id;
        dispatch(function () use ($linkId, $productId): void {
            try {
                app(self::class)->enrichProduct($linkId, (string) $productId);
            } catch (\Throwable $e) {
                Log::warning('[TikTokLinkEstimate] background enrich failed', [
                    'link_request_id' => $linkId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    /** Lấy thông tin sản phẩm + ước tính hoàn tiền cho link TikTok đã tạo (best-effort). */
    public function enrichProduct(int $linkId, string $productId): void
    {
        $update = $this->productUpdate($productId);

        if ($update !== []) {
            LinkRequest::where('id', $linkId)->update($update);
        }
    }

    /** @return array<string,mixed> các cột cần cập nhật (rỗng nếu không lấy được) */
    private function productUpdate(string $productId): array
    {
        try {
            $product = $this->productService->getProduct($productId);
        } catch (TikTokServiceException $e) {
            Log::warning('[TikTokLinkEstimate] Product info failed (best-effort)', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);

            return [];
        }

        if ($product->getProductId() === '') {
            return [];
        }

        $update = [];
        $this->applyEstimate($update, $product);

        return $update;
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
            // Reuse the canonical TikTokCashbackCalculator so the estimate of a
            // given commission always equals the wallet credit for the same
            // commission/tier (tier from commission ratio, no 10% cut).
            $commissionAmount = floor($price * $ratePct / 10000);

            $cashback = $this->cashbackCalculator->calculateFromCommission((float) $commissionAmount, $price);

            $update['estimated_cashback']     = $commissionAmount;
            $update['user_estimated_cashback'] = $cashback['cashback_amount'];
            $update['cashback_rate']           = $cashback['cashback_rate'];
        }
    }
}
