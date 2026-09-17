<?php

namespace App\Services\Strategies;

use App\Contracts\AffiliateLinkStrategy;
use App\Models\LinkRequest;
use App\Models\Setting;
use App\Services\UrlResolverService;
use Illuminate\Support\Facades\Log;

class DirectLinkStrategy implements AffiliateLinkStrategy
{
    public function __construct(
        private readonly UrlResolverService $urlResolver,
    ) {}

    public function handle(LinkRequest $linkRequest): void
    {
        $affiliateId = Setting::get('affiliate.direct.shopee_affiliate_id', '');

        $originalUrl = $linkRequest->original_url;

        $mustResolve = $this->urlResolver->isShortLink($originalUrl);
        $mayResolve = Setting::get('affiliate.direct.resolve_shortlink', 'true') === 'true';

        if ($mustResolve || ($mayResolve && $this->urlResolver->needsResolution($originalUrl))) {
            $resolved = $this->urlResolver->resolve($originalUrl);

            if ($resolved === null || ! $this->urlResolver->isShopeeLanding($resolved)) {
                Log::warning('[Resolver] Could not resolve Shopee short link to a landing URL', [
                    'original_url' => $originalUrl,
                    'resolved_url' => $resolved,
                ]);

                $linkRequest->update([
                    'status' => 'failed',
                    'notes' => 'Không lấy được sản phẩm Shopee từ link rút gọn. Vui lòng thử lại.',
                ]);

                return;
            }

            $originalUrl = $resolved;
        }

        $cleanUrl = explode('?', $originalUrl)[0];
        $encodedUrl = rawurlencode($cleanUrl);
        $subId = $linkRequest->user->username ?? '';

        $affiliateUrl = 'https://s.shopee.vn/an_redir'
            .'?origin_link='.$encodedUrl
            .'&affiliate_id='.$affiliateId
            .'&sub_id='.$subId;

        $linkRequest->update([
            'affiliate_url' => $affiliateUrl,
            'status' => 'completed',
        ]);
    }
}
