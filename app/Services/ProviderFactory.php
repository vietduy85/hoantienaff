<?php

namespace App\Services;

use App\Contracts\AffiliateProviderInterface;
use App\Enums\Platform;
use RuntimeException;

class ProviderFactory
{
    public function __construct(
        private array $providers,
    ) {}

    public function detectPlatform(string $url): Platform
    {
        $url = strtolower($url);

        $rules = [
            'shopee' => Platform::SHOPEE,
            'lazada' => Platform::LAZADA,
            'tiktok' => Platform::TIKTOK,
            'nhathuoclongchau' => Platform::LONG_CHAU,
            'longchau' => Platform::LONG_CHAU,
            'pharmacity' => Platform::PHARMACITY,
            'traveloka' => Platform::TRAVELOKA,
            'agoda' => Platform::AGODA,
            'booking' => Platform::BOOKING,
        ];

        foreach ($rules as $keyword => $platform) {
            if (str_contains($url, $keyword)) {
                return $platform;
            }
        }

        return Platform::OTHER;
    }

    public function getProvider(string $url): AffiliateProviderInterface
    {
        $platform = $this->detectPlatform($url);

        if ($platform === Platform::OTHER) {
            throw new RuntimeException("Unsupported platform for URL: {$url}");
        }

        foreach ($this->providers as $provider) {
            if ($provider->supportedPlatform() === $platform) {
                return $provider;
            }
        }

        throw new RuntimeException("No provider found for platform: {$platform->value}");
    }

    public function getAllProviders(): array
    {
        return $this->providers;
    }
}
