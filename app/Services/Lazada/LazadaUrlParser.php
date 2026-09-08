<?php

namespace App\Services\Lazada;

/**
 * Validates a pasted URL and recognises it as a Lazada product page URL.
 *
 * Official Lazada marketplace domains across the 6 supported countries. We only
 * recognise these hosts (the API owns product validation); short/`c.` links are
 * not resolved here — the pasted PDP/ALP URL is passed through verbatim.
 */
class LazadaUrlParser
{
    public const DOMAINS = [
        'lazada.vn',
        'lazada.sg',
        'lazada.com.my',
        'lazada.co.th',
        'lazada.com.ph',
        'lazada.co.id',
        'lazada.shop',
    ];

    /**
     * Assert the given URL is a valid http(s) Lazada URL.
     *
     * Returns the original URL unchanged on success (it is passed straight to
     * the Lazada getlink API). Throws LazadaException with a friendly message.
     */
    public function assertLazadaUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') === '') {
            throw new LazadaException(
                '[LazadaUrlParser] Invalid URL',
                422,
                'Link không hợp lệ. Vui lòng dán một link sản phẩm Lazada hợp lệ.',
            );
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new LazadaException(
                '[LazadaUrlParser] Unsupported scheme ' . $scheme,
                422,
                'Link không hợp lệ. Vui lòng dán một link sản phẩm Lazada hợp lệ.',
            );
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if (!$this->isLazadaHost($host)) {
            throw new LazadaException(
                '[LazadaUrlParser] Not a Lazada host: ' . $host,
                422,
                'Chỉ hỗ trợ link sản phẩm Lazada. Vui lòng dán đúng link Lazada.',
            );
        }

        return $url;
    }

    public function isLazadaHost(string $host): bool
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        foreach (self::DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}