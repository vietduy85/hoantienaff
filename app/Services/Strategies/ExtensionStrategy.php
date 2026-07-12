<?php

namespace App\Services\Strategies;

use App\Contracts\AffiliateLinkStrategy;
use App\Models\LinkRequest;

class ExtensionStrategy implements AffiliateLinkStrategy
{
    public function handle(LinkRequest $linkRequest): void
    {
        // LinkRequest already has status='pending' from DashboardController.
        // Browser Extension polls pending jobs via GET /api/extension/jobs.
        // No changes needed.
    }
}
