<?php

namespace App\Services\AccessTrade;

use App\Models\LinkRequest;
use App\Services\ProductMetadata\ProductMetadataService;
use Illuminate\Support\Facades\Log;

/**
 * Thông tin sản phẩm (tải trang merchant) + tiền hoàn ước tính (chi tiết campaign)
 * cho link AccessTrade ĐÃ tạo. Best-effort: không bao giờ ném lỗi, không ảnh hưởng link.
 * Chạy trong worker queue `link-enrich` khi có, nếu không thì chạy tại chỗ.
 */
class AccessTradeLinkEnricher
{
    public function __construct(
        private readonly ProductMetadataService $productMetadata,
        private readonly AccessTradeCampaignService $campaigns,
        private readonly AccessTradeCommissionEstimateService $estimator,
    ) {}

    public function enrich(LinkRequest $link, string $url, ?string $campaignId): void
    {
        $this->enrichWithProductMetadata($link, $url);

        // Ước tính cần giá sản phẩm từ bước trên.
        $this->applyAccessTradeEstimate($link, $campaignId);
    }

    public function enrichById(int $linkId, string $url, ?string $campaignId): void
    {
        $link = LinkRequest::find($linkId);

        if ($link !== null) {
            $this->enrich($link, $url, $campaignId);
        }
    }

    /**
     * Best-effort product metadata enrichment for a completed AccessTrade link.
     *
     * Never throws and never overwrites an existing value with null. The
     * affiliate link is already persisted, so metadata problems are silent to
     * the user (logged only).
     */
    private function enrichWithProductMetadata(LinkRequest $link, string $url): void
    {
        try {
            $metadata = $this->productMetadata->fetch($url);
        } catch (\Throwable $e) {
            Log::warning('[AccessTradeEnrich] Product metadata fetch failed', [
                'link_request_id' => $link->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($metadata === null) {
            return;
        }

        $update = array_filter([
            'product_name' => $metadata['name'],
            'product_image' => $metadata['image'],
            'product_price' => $metadata['price'],
            'product_link' => $url,
        ], fn ($value) => $value !== null);

        if ($update !== []) {
            $link->update($update);
        }
    }

    /**
     * Best-effort expected-cashback for a completed AccessTrade link.
     *
     * AccessTrade exposes no per-product commission, so the campaign's
     * advertised `max_com` is used ONLY when the campaign policy corroborates it
     * as the single commission rate (see
     * {@see AccessTradeCommissionEstimateService}). When it cannot be
     * corroborated the estimate columns stay null so the UI shows "Chưa tính
     * được tiền hoàn dự kiến" instead of a guessed amount. Never throws and
     * never affects the already-completed link.
     */
    private function applyAccessTradeEstimate(LinkRequest $link, ?string $campaignId): void
    {
        try {
            $campaignId = trim((string) $campaignId);
            $price = (float) ($link->product_price ?? 0);

            if ($campaignId === '' || $price <= 0) {
                return;
            }

            $detail = $this->campaigns->detail($campaignId);

            if ($detail === null
                || $detail['approval'] !== 'successful'
                || (string) $detail['status'] !== '1') {
                return;
            }

            $estimate = $this->estimator->estimate(
                $price,
                $detail['max_commission'],
                $detail['commission_policy'],
            );

            if ($estimate === null) {
                return;
            }

            $link->update([
                'estimated_cashback' => $estimate['commission'],
                'cashback_rate' => $estimate['cashback_rate'],
                'user_estimated_cashback' => $estimate['user_estimated_cashback'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AccessTradeEnrich] AccessTrade estimate failed', [
                'link_request_id' => $link->id,
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }
    }

}
