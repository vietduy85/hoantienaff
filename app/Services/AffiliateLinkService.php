<?php

namespace App\Services;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Services\Strategies\DirectLinkStrategy;
use App\Services\Strategies\ExtensionStrategy;

class AffiliateLinkService
{
    public function __construct(
        private readonly ExtensionStrategy $extensionStrategy,
        private readonly DirectLinkStrategy $directStrategy,
    ) {}

    public function handle(LinkRequest $linkRequest, string $context = 'dashboard'): void
    {
        $platform = strtolower($linkRequest->platform ?? '');

        if (!str_contains($platform, 'shopee')) {
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
        $platform = strtolower($linkRequest->platform ?? '');

        if (!str_contains($platform, 'shopee')) {
            return;
        }

        $this->extensionStrategy->handle($linkRequest);
    }
}
