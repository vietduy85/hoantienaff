<?php

namespace App\Services\TikTok;

use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use App\Services\TikTok\DTOs\TikTokProductDTO;

class TikTokProductService
{
    public function __construct(
        private readonly RioHubClient $client,
    ) {}

    /**
     * Retrieve product info from RioHub.
     *
     * @param  string|int  $productId  RioHub product identifier.
     *
     * @throws TikTokServiceException On API errors.
     */
    public function getProduct(string|int $productId): TikTokProductDTO
    {
        if (empty($productId)) {
            throw new TikTokServiceException('[getProduct] product_id is required');
        }

        try {
            $response = $this->client->getProduct($productId);
        } catch (RioHubException $e) {
            throw TikTokServiceException::fromRioHubException($e, 'getProduct');
        }

        $data = $response->getResult();

        if (empty($data)) {
            throw new TikTokServiceException(
                "[getProduct] No product data returned for id: {$productId}",
            );
        }

        return TikTokProductDTO::fromRioHubResponse($data);
    }
}
