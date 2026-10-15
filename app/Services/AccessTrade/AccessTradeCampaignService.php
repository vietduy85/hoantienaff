<?php

namespace App\Services\AccessTrade;

/**
 * Read + map AccessTrade campaigns (Phase D).
 *
 * A campaign is only usable when `approval=successful` AND `status=1`
 * (Running). Campaign → domain mapping prefers the normalized
 * `/v1/cashback/campaigns` `merchant` field (a registrable domain) and falls
 * back to the hostname of `/v1/campaigns[].url`.
 */
class AccessTradeCampaignService
{
    public function __construct(
        private readonly AccessTradeClient $client,
        private readonly AccessTradeConfig $config,
    ) {}

    /**
     * Normalized list of approval=successful campaigns (Running first).
     *
     * @return array<int, array{
     *     campaign_id: string, name: string, merchant: ?string, domain: ?string,
     *     approval: ?string, status: ?string, start_time: ?string, end_time: ?string,
     *     min_commission: ?float, max_commission: ?float
     * }>
     */
    public function campaigns(): array
    {
        $byId = [];

        foreach ($this->fetch('/v1/campaigns', ['approval' => 'successful']) as $item) {
            $normalized = $this->normalizeLegacyCampaign($item);
            if ($normalized !== null) {
                $byId[$normalized['campaign_id']] = $normalized;
            }
        }

        foreach ($this->fetch('/v1/cashback/campaigns', []) as $item) {
            $normalized = $this->normalizeCashbackCampaign($item);
            if ($normalized === null) {
                continue;
            }

            // Enrich (do not clobber) an existing entry with commission range
            // and the domain-form `merchant` field.
            if (isset($byId[$normalized['campaign_id']])) {
                $byId[$normalized['campaign_id']] = array_merge(
                    $normalized,
                    array_filter($byId[$normalized['campaign_id']], fn ($v) => $v !== null),
                );
            } else {
                $byId[$normalized['campaign_id']] = $normalized;
            }
        }

        return array_values($byId);
    }

    /**
     * Find the campaign whose registered domain matches the given host.
     *
     * Matching is EXACT (host or `.<host>` suffix) — never fuzzy name. Returns
     * null when there is no unambiguous match.
     *
     * @param  array<int, array<string, mixed>>|null  $campaigns
     * @return array<string, mixed>|null
     */
    public function forHost(string $host, ?array $campaigns = null): ?array
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host === '') {
            return null;
        }

        $campaigns ??= $this->campaigns();
        $matches = [];

        foreach ($campaigns as $campaign) {
            $domain = $campaign['domain'] ?? null;
            if (! is_string($domain) || $domain === '') {
                continue;
            }

            $domain = strtolower(ltrim($domain, '.'));

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                $matches[] = $campaign;
            }
        }

        if (count($matches) !== 1) {
            // 0 = unsupported, >1 = ambiguous → never guess.
            return null;
        }

        return $matches[0];
    }

    /**
     * Lightest possible authenticated read, used by the "Kiểm tra kết nối"
     * button. Success here means the API really answered (not just a token
     * being present).
     *
     * @return array{ok: bool, message: string, sampled: int}
     */
    public function testConnection(): array
    {
        $data = $this->client->get('/v1/campaigns', [
            'approval' => 'successful',
            'limit' => 1,
            'page' => 1,
        ], retry: false);

        $sampled = is_array($data['data'] ?? null) ? count($data['data']) : 0;
        $total = $data['total'] ?? null;

        $message = $total !== null
            ? sprintf('Kết nối thành công. Tài khoản có %s campaign đã duyệt.', (string) $total)
            : 'Kết nối thành công.';

        return ['ok' => true, 'message' => $message, 'sampled' => $sampled];
    }

    /**
     * Fetch all pages of a paginated list endpoint, defensively.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $path, array $query): array
    {
        $query = array_merge(['page' => 1, 'limit' => 200], $query);
        $data = $this->client->get($path, $query);

        $items = $data['data'] ?? null;

        // Some endpoints wrap the list one level deeper.
        if (is_array($items) && isset($items['campaigns']) && is_array($items['campaigns'])) {
            $items = $items['campaigns'];
        }

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function normalizeLegacyCampaign(array $item): ?array
    {
        $id = $this->stringOrNull($item['id'] ?? $item['campaign_id'] ?? null);

        if ($id === null) {
            return null;
        }

        return [
            'campaign_id' => $id,
            'name' => (string) ($item['name'] ?? ''),
            'merchant' => $this->stringOrNull($item['merchant'] ?? null),
            'domain' => $this->hostFromUrl($item['url'] ?? null),
            'approval' => $this->stringOrNull($item['approval'] ?? null),
            'status' => isset($item['status']) ? (string) $item['status'] : null,
            'start_time' => $this->stringOrNull($item['start_time'] ?? null),
            'end_time' => $this->stringOrNull($item['end_time'] ?? null),
            'min_commission' => null,
            'max_commission' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function normalizeCashbackCampaign(array $item): ?array
    {
        $id = $this->stringOrNull($item['campaign_id'] ?? $item['id'] ?? null);

        if ($id === null) {
            return null;
        }

        return [
            'campaign_id' => $id,
            'name' => (string) ($item['name'] ?? ''),
            'merchant' => $this->stringOrNull($item['merchant'] ?? null),
            'domain' => $this->hostFromUrl($item['merchant'] ?? $item['url'] ?? null),
            'approval' => $this->stringOrNull($item['approval'] ?? null),
            'status' => isset($item['status']) ? (string) $item['status'] : null,
            'start_time' => null,
            'end_time' => $this->stringOrNull($item['end_date'] ?? null),
            'min_commission' => $this->floatOrNull($item['min_commission'] ?? null),
            'max_commission' => $this->floatOrNull($item['max_commission'] ?? null),
        ];
    }

    private function hostFromUrl(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.ltrim($value, '/');
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host)) ?? strtolower($host);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
