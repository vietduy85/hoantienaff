<?php

namespace App\Services\PriceComparison;

use App\Services\PriceComparison\Contracts\CatalogProvider;
use InvalidArgumentException;

/**
 * Registry of catalog providers keyed by their source identifier.
 * Register providers via the "catalog-providers" container tag.
 */
final class PriceComparisonManager
{
    /**
     * @var array<string, CatalogProvider>
     */
    private array $providers = [];

    /**
     * @param  iterable<CatalogProvider>  $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(CatalogProvider $provider): void
    {
        $this->providers[$provider->source()] = $provider;
    }

    public function forSource(string $source): CatalogProvider
    {
        return $this->providers[$source]
            ?? throw new InvalidArgumentException("Unsupported catalog source: {$source}");
    }

    /**
     * @return array<string, CatalogProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }
}