<?php

namespace App\Contracts;

use App\Enums\Platform;

interface AffiliateProviderInterface
{
    /**
     * @param  string  $url     Product URL to create affiliate link for.
     * @param  string|null  $subId  Optional sub-id for user tracking (typically username).
     */
    public function createLink(string $url, ?string $subId = null): array;
    public function supportedPlatform(): Platform;
}
