<?php

namespace App\Services;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Services\Strategies\DirectLinkStrategy;
use App\Services\Strategies\ExtensionStrategy;
use App\Services\Url\PlatformResolver;

class AffiliateLinkService
{
    public function __construct(
        private readonly ExtensionStrategy $extensionStrategy,
        private readonly DirectLinkStrategy $directStrategy,
        private readonly PlatformResolver $platformResolver,
    ) {}

    public function handle(LinkRequest $linkRequest, string $context = 'dashboard'): void
    {
        if (! $this->isShopeeLink($linkRequest)) {
            return;
        }

        if (! Setting::extensionShortlinkEnabled()) {
            $this->directStrategy->handle($linkRequest);

            return;
        }

        $strategyKey = match ($context) {
            'admin' => 'affiliate.admin.strategy',
            default => 'affiliate.dashboard.strategy',
        };

        $default = $context === 'admin' ? 'extension' : 'direct';
        $strategy = Setting::get($strategyKey, $default);

        if ($strategy === 'direct') {
            $this->directStrategy->handle($linkRequest);

            return;
        }

        $this->extensionStrategy->handle($linkRequest);
    }

    public function handleViaExtension(LinkRequest $linkRequest): void
    {
        if (! $this->isShopeeLink($linkRequest)) {
            return;
        }

        if (! Setting::extensionShortlinkEnabled()) {
            $this->directStrategy->handle($linkRequest);

            return;
        }

        $this->extensionStrategy->handle($linkRequest);
    }

    /**
     * Shopee links are identified by resolving the original URL hostname
     * (never by a substring of the platform label), so `ShopeeFood` or spoof
     * hosts are not misrouted into the Shopee strategy.
     */
    private function isShopeeLink(LinkRequest $linkRequest): bool
    {
        $url = (string) ($linkRequest->original_url ?? '');

        if ($url === '') {
            return false;
        }

        return $this->platformResolver->resolve($url)->isShopee();
    }
}
