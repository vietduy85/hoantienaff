<?php

namespace App\Services\Lazada;

use App\Models\LinkRequest;
use App\Models\User;

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
    public function create(LinkRequest $link, string $url, User $user): void
    {
        $this->urlParser->assertLazadaUrl($url);

        $dto = $this->productService->createLink(
            $url,
            (string) $user->id,
            (string) $user->username,
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

        if (($name = $dto->getProductName()) !== null && $name !== '') {
            $update['product_name'] = $name;
        }

        if (($image = $dto->getProductImage()) !== null && $image !== '') {
            $update['product_image'] = $image;
        }

        if (($price = $dto->getProductPrice()) !== null && $price > 0) {
            $update['product_price'] = (int) round($price);
        }

        $rate = $dto->getCommissionRatePct();
        if ($rate !== null && $rate > 0) {
            $update['cashback_rate'] = $rate;
        }

        $amount = $dto->getCommissionAmount();
        if ($amount !== null && $amount > 0) {
            $update['estimated_cashback'] = $amount;

            // User cashback must follow the same tier rule used at wallet
            // credit time (LazadaCashbackCalculator): commission/price ratio
            // >= 0.52 -> 70% | >= 0.12 -> 60% | otherwise 50%, no 10% cut.
            $cashback = $this->cashbackCalculator->calculate(
                (float) $amount,
                (float) ($dto->getProductPrice() ?? 0.0),
                true,
            );

            $update['cashback_rate']           = $cashback['cashback_rate'];
            $update['user_estimated_cashback'] = $cashback['cashback_amount'];
        }

        $link->update($update);
    }
}