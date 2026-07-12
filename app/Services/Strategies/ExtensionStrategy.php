<?php

namespace App\Services\Strategies;

use App\Contracts\AffiliateLinkStrategy;
use App\Models\LinkRequest;

class ExtensionStrategy implements AffiliateLinkStrategy
{
    public function handle(LinkRequest $linkRequest): void
    {
        $linkRequest->update(['status' => 'pending']);
    }
}
