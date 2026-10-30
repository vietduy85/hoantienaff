<?php

namespace App\Services\Lazada;

use App\Models\LinkRequest;
use App\Models\User;
use App\Support\LinkEnrichment;

/**
 * Phase Lazada 1 orchestration: Lazada product URL → getlink affiliate link →
 * product info + commission preview → persist into link_requests.
 *
 * This NEVER touches orders, wallet or transaction commission. It only builds
 * the affiliate link and a preview (rate/amount) for display. The user tracking
 * identifier is sent to the getlink API as subId1 (user id) + subId2 (username)
 * so future order-sync can attribute conversions to this user via subId1..6.
 */
class LazadaLinkEstimateService
{
    public function __construct(
        private readonly LazadaUrlParser $urlParser,
        private readonly LazadaProductService $productService,
        private readonly LazadaCashbackCalculator $cashbackCalculator,
    ) {}

    /**
     * Validate the URL, create the affiliate link and enrich the given
     * LinkRequest with product info + commission preview.
     *
     * Throws LazadaException on hard failure (invalid URL, product not found,
     * API error, credentials missing) so the caller can mark the request failed
     * and show a friendly error.
     */
    public function create(LinkRequest $link, string $url, User $user, bool $fast = false, bool $deferProduct = false): void
    {
        $this->urlParser->assertLazadaUrl($url);

        // Có worker queue: link trả về ngay sau 1 lần gọi getlink; feed sản phẩm chạy trong worker.
        $defer = ! $fast && $deferProduct && LinkEnrichment::viaQueue();

        $dto = $this->productService->createLink(
            $url,
            (string) $user->id,
            (string) $user->username,
            ! $fast && ! $defer,
        );

        $update = [
            'affiliate_url'  => $dto->getAffiliateUrl(),
            'status'         => 'completed',
            'data_source'    => 'lazada-api',
            'product_link'   => $url,
            'platform'       => 'Lazada',
        ];

        $productId = $dto->getProductId();
        if ($productId !== null && $productId !== '') {
            $update['item_id'] = (int) $productId;
        }

        // Chế độ nhanh: chỉ lưu affiliate link, không thông tin sản phẩm / ước tính hoàn tiền.
        if ($fast) {
            $link->update($update);

            return;
        }

        if ($defer) {
            $link->update($update);

            $linkId = $link->id;
            $fallbackName = $dto->getProductName();
            $listedRate = $dto->getCommissionRatePct();
            LinkEnrichment::dispatch(static function () use ($linkId, $productId, $fallbackName, $listedRate): void {
                app(self::class)->enrichProduct($linkId, $productId, $fallbackName, $listedRate);
            });

            return;
        }

        $this->applyProduct($update, $dto->getProductName(), $dto->getProductImage(), $dto->getProductPrice(),
            $dto->getCommissionRatePct(), $dto->getCommissionAmount());

        $link->update($update);
    }

    /** Feed sản phẩm (tên/ảnh/giá/hoa hồng) cho link Lazada đã tạo — chạy trong worker, best-effort. */
    public function enrichProduct(int $linkId, ?string $productId, ?string $fallbackName, ?float $listedRate): void
    {
        $details = $this->productService->productDetails($productId);

        $update = [];
        $this->applyProduct($update, $details['name'] ?? $fallbackName, $details['image'], $details['price'],
            $listedRate ?? $details['rate'], $details['amount']);

        // Luôn ghi product_name (kể cả tên dự phòng) để UI ngừng chờ.
        $update['product_name'] ??= $fallbackName ?? 'Sản phẩm Lazada';

        LinkRequest::where('id', $linkId)->update($update);
    }

    /** @param array<string, mixed> $update */
    private function applyProduct(array &$update, ?string $name, ?string $image, ?float $price, ?float $rate, ?float $amount): void
    {
        if ($name !== null && $name !== '') {
            $update['product_name'] = $name;
        }

        if ($image !== null && $image !== '') {
            $update['product_image'] = $image;
        }

        if ($price !== null && $price > 0) {
            $update['product_price'] = (int) round($price);
        }

        if ($rate !== null && $rate > 0) {
            $update['cashback_rate'] = $rate;
        }

        if ($amount !== null && $amount > 0) {
            $update['estimated_cashback'] = $amount;

            // User cashback must follow the same tier rule used at wallet
            // credit time (LazadaCashbackCalculator): commission/price ratio
            // >= 0.52 -> 70% | >= 0.12 -> 60% | otherwise 50%, no 10% cut.
            $cashback = $this->cashbackCalculator->calculate(
                (float) $amount,
                (float) ($price ?? 0.0),
                false,
            );

            $update['cashback_rate']           = $cashback['cashback_rate'];
            $update['user_estimated_cashback'] = $cashback['cashback_amount'];
        }
    }
}