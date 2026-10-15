<?php

namespace App\Services\AccessTrade;

use Throwable;

/**
 * Create AccessTrade tracking links (Phase D).
 *
 * Hard rule: this service NEVER returns an empty link. Any response without a
 * usable `aff_link`/`short_link`/`first_link` becomes an
 * {@see AccessTradeException}, so a `link_requests` row is never marked
 * `completed` with a null/blank `affiliate_url`.
 */
class AccessTradeLinkService
{
    public function __construct(
        private readonly AccessTradeClient $client,
        private readonly AccessTradeCampaignService $campaigns,
        private readonly AccessTradeConfig $config,
    ) {}

    /**
     * Resolve the campaign for a URL (unless pinned) and create a tracking link.
     *
     * @return array{affiliate_url: string, campaign_id: string}
     *
     * @throws AccessTradeException when no campaign matches or the API returns
     *                              no usable link.
     */
    public function createForUrl(string $url, ?string $campaignId = null, ?string $subId = null): array
    {
        $campaignId = trim((string) $campaignId);

        if ($campaignId === '') {
            $host = $this->host($url);
            $campaign = $host !== null ? $this->campaigns->forHost($host) : null;

            if ($campaign === null) {
                throw new AccessTradeException(
                    'No AccessTrade campaign matched the URL domain.',
                    0,
                    'Chưa xác định được campaign AccessTrade cho domain này. Vui lòng cấu hình domain/merchant.',
                );
            }

            $campaignId = (string) $campaign['campaign_id'];
        }

        return [
            'affiliate_url' => $this->create($url, $campaignId, $subId),
            'campaign_id' => $campaignId,
        ];
    }

    /**
     * POST /v1/product_link/create and return the best link.
     *
     * @throws AccessTradeException
     */
    public function create(string $url, string $campaignId, ?string $subId = null): string
    {
        $url = trim($url);
        $campaignId = trim($campaignId);

        if ($url === '' || $campaignId === '') {
            throw new AccessTradeException(
                'AccessTrade link creation requires a non-empty url and campaign_id.',
                0,
                'Thiếu URL hoặc campaign AccessTrade để tạo link.',
            );
        }

        $payload = [
            'campaign_id' => $campaignId,
            'urls' => [$url],
            'url_enc' => true,
        ];

        if ($subId !== null && trim($subId) !== '') {
            $payload['sub1'] = trim($subId);
        }

        $data = $this->client->post('/v1/product_link/create', $payload);

        if (($data['success'] ?? true) === false) {
            throw new AccessTradeException(
                'AccessTrade reported success=false.',
                0,
                'AccessTrade không tạo được link cho URL này.',
            );
        }

        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;

        $error = $payload['error_link'] ?? [];
        if (is_array($error) && $error !== []) {
            throw new AccessTradeException(
                'AccessTrade returned error_link entries.',
                0,
                'AccessTrade từ chối URL (error_link). Không có link được tạo.',
            );
        }

        $link = $this->extractLink($payload['success_link'] ?? null);

        if ($link === null) {
            throw new AccessTradeException(
                'AccessTrade response contained no usable link.',
                0,
                'AccessTrade không trả về link hợp lệ. Vui lòng thử lại.',
            );
        }

        return $link;
    }

    private function extractLink(mixed $successLinks): ?string
    {
        if (! is_array($successLinks)) {
            return null;
        }

        foreach ($successLinks as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (['aff_link', 'short_link', 'first_link'] as $key) {
                $value = $entry[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    private function host(string $url): ?string
    {
        try {
            $host = parse_url($url, PHP_URL_HOST);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host)) ?? strtolower($host);
    }
}
