<?php

namespace App\Services\PromotionNews\Providers;

use App\Services\PromotionNews\Concerns\ParsesNextData;
use App\Services\PromotionNews\Contracts\PromotionNewsProvider;
use App\Services\PromotionNews\Exceptions\PromotionNewsProviderException;
use App\Services\PromotionNews\Support\PromotionNewsUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class AbstractPromotionNewsProvider implements PromotionNewsProvider
{
    use ParsesNextData;

    protected const TIMEOUT = 15;

    protected const CONNECT_TIMEOUT = 8;

    protected const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    abstract protected function homepage(): string;

    protected function fetchHtml(): string
    {
        $source = $this->source();

        try {
            $response = Http::withHeaders([
                'User-Agent' => static::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'vi,en;q=0.8',
            ])
                ->connectTimeout(static::CONNECT_TIMEOUT)
                ->timeout(static::TIMEOUT)
                ->get($this->homepage());
        } catch (ConnectionException $e) {
            throw new PromotionNewsProviderException("{$source}: homepage connection failed", 0, $e);
        } catch (Throwable $e) {
            throw new PromotionNewsProviderException("{$source}: homepage request failed", 0, $e);
        }

        if ($response->failed()) {
            throw new PromotionNewsProviderException("{$source}: homepage returned HTTP {$response->status()}");
        }

        return $response->body();
    }

    protected function imageUrl(mixed $value): ?string
    {
        return is_string($value) && PromotionNewsUrl::isValid($value) ? trim($value) : null;
    }

    protected function landingUrl(mixed $value, string $base): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return PromotionNewsUrl::resolve($value, $base);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'Asia/Ho_Chi_Minh');
        } catch (Throwable) {
            return null;
        }
    }

    protected function deterministicId(string ...$parts): string
    {
        return substr(sha1(implode('|', $parts)), 0, 24);
    }
}
