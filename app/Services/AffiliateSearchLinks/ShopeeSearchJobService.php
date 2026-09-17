<?php

namespace App\Services\AffiliateSearchLinks;

use App\Models\LinkRequest;
use App\Models\User;
use App\Services\AffiliateLinkService;
use App\Services\AffiliateSearchLinks\Providers\ShopeeAffiliateSearchLinkProvider;

class ShopeeSearchJobService
{
    public function __construct(
        private readonly AffiliateLinkService $affiliateLinkService,
    ) {}

    /**
     * Create (or reuse) a Shopee Extension job for the given search keyword,
     * scoped to the authenticated user. The LinkRequest row itself acts as the
     * user-specific cache: a completed job for the same user + search URL is
     * reused instead of enqueuing a duplicate, so affiliate URLs never leak
     * across users.
     *
     * @return array{request_id: int, status: string, affiliate_url: string|null}
     */
    public function ensureForKeyword(string $keyword, User $user): array
    {
        $searchUrl = (new ShopeeAffiliateSearchLinkProvider())->buildSearchUrl($keyword);

        $recent = LinkRequest::query()
            ->where('user_id', $user->id)
            ->where('original_url', $searchUrl)
            ->where('platform', 'Shopee')
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->orderByDesc('id')
            ->get()
            ->first(function (LinkRequest $lr) {
                return $lr->status !== 'completed' || $lr->affiliate_url !== '';
            });

        if ($recent) {
            return [
                'request_id'    => $recent->id,
                'status'        => $recent->status === 'completed' ? 'ready' : $recent->status,
                'affiliate_url' => $recent->affiliate_url ?: null,
            ];
        }

        $link = LinkRequest::create([
            'user_id'      => $user->id,
            'original_url' => $searchUrl,
            'platform'     => 'Shopee',
            'status'       => 'processing',
        ]);

        $this->affiliateLinkService->handleViaExtension($link);

        return [
            'request_id'    => $link->id,
            'status'        => $link->status ?: 'pending',
            'affiliate_url' => null,
        ];
    }
}