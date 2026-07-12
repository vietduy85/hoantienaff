<?php

namespace App\Services\Strategies;

use App\Contracts\AffiliateLinkStrategy;
use App\Models\LinkRequest;
use App\Models\Setting;
use App\Services\UrlResolverService;

class DirectLinkStrategy implements AffiliateLinkStrategy
{
    public function __construct(
        private readonly UrlResolverService $urlResolver,
    ) {}

    public function handle(LinkRequest $linkRequest): void
    {
        $affiliateId = Setting::get('affiliate.direct.shopee_affiliate_id', '');

        $originalUrl = $linkRequest->original_url;

        if (Setting::get('affiliate.direct.resolve_shortlink', 'true') === 'true') {
            $resolved = $this->urlResolver->resolve($originalUrl);
            if ($resolved !== null) {
                $originalUrl = $resolved;
            }
        }

        $cleanUrl = explode('?', $originalUrl)[0];
        $encodedUrl = rawurlencode($cleanUrl);
        $subId = $linkRequest->user->username ?? '';

        $affiliateUrl = 'https://s.shopee.vn/an_redir'
            . '?origin_link=' . $encodedUrl
            . '&affiliate_id=' . $affiliateId
            . '&sub_id=' . $subId;

        $linkRequest->update([
            'affiliate_url' => $affiliateUrl,
            'status'        => 'completed',
        ]);
    }
}
