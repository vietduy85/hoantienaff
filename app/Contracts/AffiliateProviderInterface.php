<?php

namespace App\Contracts;

use App\Enums\Platform;

interface AffiliateProviderInterface
{
    public function createLink(string $url): array;
    public function supportedPlatform(): Platform;
}
