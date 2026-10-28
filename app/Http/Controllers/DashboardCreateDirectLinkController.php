<?php

namespace App\Http\Controllers;

use App\Models\AffiliateCache;
use App\Models\LinkRequest;
use App\Models\Setting;
use App\Services\AccessTrade\AccessTradeCampaignService;
use App\Services\AccessTrade\AccessTradeCommissionEstimateService;
use App\Services\AccessTrade\AccessTradeConfig;
use App\Services\AccessTrade\AccessTradeException;
use App\Services\AccessTrade\AccessTradeLinkService;
use App\Services\AffiliateCacheService;
use App\Services\CashbackCalculator;
use App\Services\Lazada\LazadaException;
use App\Services\Lazada\LazadaLinkEstimateService;
use App\Services\ProductDataService;
use App\Services\ProductMetadata\ProductMetadataService;
use App\Services\TikTok\TikTokLinkEstimateService;
use App\Services\TikTok\TikTokServiceException;
use App\Services\Url\PlatformResolver;
use App\Services\UrlResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DashboardCreateDirectLinkController extends Controller
{
    public function __construct(
        private readonly ProductDataService $productData,
        private readonly CashbackCalculator $cashbackCalculator,
        private readonly AffiliateCacheService $cacheService,
        private readonly UrlResolverService $urlResolver,
        private readonly TikTokLinkEstimateService $tiktokLinkEstimate,
        private readonly LazadaLinkEstimateService $lazadaLinkEstimate,
        private readonly PlatformResolver $platformResolver,
        private readonly AccessTradeConfig $accessTradeConfig,
        private readonly AccessTradeLinkService $accessTradeLink,
        private readonly AccessTradeCampaignService $accessTradeCampaigns,
        private readonly AccessTradeCommissionEstimateService $accessTradeEstimate,
        private readonly ProductMetadataService $productMetadata,
    ) {}

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'original_url' => ['required', 'url', 'max:2048'],
        ]);

        $user = auth()->user();
        $target = $this->platformResolver->resolve($validated['original_url']);
        $platform = $target->platform;
        $isShopee = $target->isShopee();

        // "Chế độ nhanh" (Fast Mode): chỉ tạo affiliate URL, không gọi ProductData,
        // không cache affiliate_cache, không dispatch closure sau response.
        $fastMode = $request->boolean('fast_mode');

        $link = LinkRequest::create([
            'user_id' => $user->id,
            'original_url' => $validated['original_url'],
            'platform' => $platform,
            'status' => $isShopee ? 'processing' : 'completed',
        ]);

        if ($isShopee) {
            $resolvedUrl = $this->urlResolver->resolve($validated['original_url']);

            if ($resolvedUrl === null || ! $this->urlResolver->isShopeeLanding($resolvedUrl)) {
                Log::warning('[Resolver] Could not resolve Shopee short link to a landing URL', [
                    'original_url' => $validated['original_url'],
                    'resolved_url' => $resolvedUrl,
                ]);

                $error = 'Không lấy được sản phẩm Shopee từ link rút gọn. Vui lòng thử lại.';

                $link->update([
                    'status' => 'failed',
                    'notes' => $error,
                ]);

                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'error' => $error,
                        'request_id' => $link->id,
                        'platform' => $platform,
                    ], 422);
                }

                return redirect()->route('dashboard')->with('error', $error);
            }

            $itemId = $this->cacheService->extractItemId($resolvedUrl);

            // Fast Mode: dừng ngay sau resolver. KHÔNG gọi affiliate_cache,
            // KHÔNG gọi ProductDataService, KHÔNG dispatch afterResponse.
            if ($fastMode) {
                return $this->storeFastShopeeLink($request, $link, $resolvedUrl, $itemId);
            }

                $cached = $itemId ? $this->cacheService->get($itemId, $platform) : null;

                if ($cached) {
                    if (config('app.affiliate_timing')) {
                        Log::info('[CACHE]', [
                            'item_id' => $cached->item_id,
                            'status' => 'HIT',
                        ]);
                    }

                    $update = [
                        'item_id' => $cached->item_id,
                        'shop_id' => $cached->shop_id,
                        'estimated_cashback' => $cached->estimated_cashback,
                        'user_estimated_cashback' => $cached->user_estimated_cashback,
                        'cashback_rate' => $cached->cashback_rate,
                        'product_name' => $cached->product_name,
                        'product_price' => $cached->product_price,
                        'product_link' => $cached->product_link,
                        'seller_commission' => $cached->seller_commission,
                        'shopee_commission' => $cached->shopee_commission,
                        'rating' => $cached->rating,
                        'product_image' => $cached->product_image,
                        'shop_name' => $cached->shop_name,
                        'sales' => $cached->sales,
                        'is_xtra' => $cached->is_xtra,
                        'data_source' => $cached->data_source,
                    ];

                    // Only trust rates from a cache row whose platform matches the
                    // resolved platform. Legacy platform=NULL rows are usable as
                    // product data, but not as commission-rate evidence.
                    $cacheRateTrusted = $this->cachePlatformMatches($cached, $platform);
                    $cachedShopeeRate = $cacheRateTrusted ? $cached->getAttribute('shopee_commission_rate') : null;
                    $cachedSellerRate = $cacheRateTrusted ? $cached->getAttribute('seller_commission_rate') : null;
                    $copiedRate = false;

                    if (($link->getAttribute('shopee_commission_rate_at_create') === null || $link->getAttribute('shopee_commission_rate_at_create') === '')
                        && $cachedShopeeRate !== null && $cachedShopeeRate !== '') {
                        $update['shopee_commission_rate_at_create'] = $cachedShopeeRate;
                        $copiedRate = true;
                    }
                    if (($link->getAttribute('seller_commission_rate_at_create') === null || $link->getAttribute('seller_commission_rate_at_create') === '')
                        && $cachedSellerRate !== null && $cachedSellerRate !== '') {
                        $update['seller_commission_rate_at_create'] = $cachedSellerRate;
                        $copiedRate = true;
                    }

                    $hasPositiveCachedRate = $this->isPositiveRate($cachedShopeeRate)
                        || $this->isPositiveRate($cachedSellerRate);

                    if ($copiedRate) {
                        $update['commission_rate_source'] = 'product-data-cache';
                        $update['commission_rate_captured_at'] = now();
                        $update['commission_rate_status'] = $hasPositiveCachedRate ? 'available' : 'unverified';
                    }

                    $link->update($update);

                    $affiliateUrl = $this->buildAffiliateUrl($resolvedUrl, $user);

                    $link->update([
                        'affiliate_url' => $affiliateUrl,
                        'status' => 'completed',
                    ]);

                    // Cache hit without a trusted positive rate: complete the link
                    // first, then enrich rates in the background without touching
                    // the affiliate URL.
                    if (! $hasPositiveCachedRate) {
                        $this->dispatchProductDataRefresh($link->id, $resolvedUrl, $itemId, $platform);
                    }
            } else {
                if ($itemId) {
                    $this->cacheService->logMiss($itemId);

                    $link->update(['item_id' => $itemId]);
                }

                $affiliateUrl = $this->buildAffiliateUrl($resolvedUrl, $user);

                $link->update([
                    'affiliate_url' => $affiliateUrl,
                ]);

                $this->dispatchProductDataRefresh($link->id, $resolvedUrl, $itemId, $platform);
            }
        } else {
            if ($target->isTikTok()) {
                try {
                    $this->tiktokLinkEstimate->create($link, $validated['original_url'], $user);
                } catch (TikTokServiceException $e) {
                    $link->update([
                        'status' => 'failed',
                        'notes' => $e->getRioHubMessage() ?? $e->getMessage(),
                    ]);

                    Log::warning('[DirectLink] TikTok link creation failed', [
                        'url' => $validated['original_url'],
                        'error' => $e->getMessage(),
                        'user_id' => $user->id,
                    ]);

                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'success' => false,
                            'error' => $this->friendlyTikTokError($e),
                            'request_id' => $link->id,
                            'platform' => $platform,
                        ], 422);
                    }

                    return redirect()->route('dashboard')
                        ->with('error', $this->friendlyTikTokError($e));
                } catch (\Throwable $e) {
                    $link->update([
                        'status' => 'failed',
                        'notes' => 'Lỗi hệ thống khi tạo link TikTok.',
                    ]);

                    Log::warning('[DirectLink] TikTok provider failed', [
                        'url' => $validated['original_url'],
                        'error' => $e->getMessage(),
                    ]);

                    $msg = 'Không thể kết nối TikTok lúc này, vui lòng thử lại sau.';

                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'success' => false,
                            'error' => $msg,
                            'request_id' => $link->id,
                            'platform' => $platform,
                        ], 502);
                    }

                    return redirect()->route('dashboard')->with('error', $msg);
                }
            } elseif ($target->isLazada()) {
                try {
                    $this->lazadaLinkEstimate->create($link, $validated['original_url'], $user);
                } catch (LazadaException $e) {
                    $link->update([
                        'status' => 'failed',
                        'notes' => $e->getUserMessage(),
                    ]);

                    Log::warning('[DirectLink] Lazada link creation failed', [
                        'url' => $validated['original_url'],
                        'error' => $e->getMessage(),
                        'user_id' => $user->id,
                    ]);

                    $friendly = $e->getUserMessage();

                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'success' => false,
                            'error' => $friendly,
                            'request_id' => $link->id,
                            'platform' => $platform,
                        ], 422);
                    }

                    return redirect()->route('dashboard')->with('error', $friendly);
                } catch (\Throwable $e) {
                    $link->update([
                        'status' => 'failed',
                        'notes' => 'Lỗi hệ thống khi tạo link Lazada.',
                    ]);

                    Log::warning('[DirectLink] Lazada provider failed', [
                        'url' => $validated['original_url'],
                        'error' => $e->getMessage(),
                    ]);

                    $msg = 'Không thể kết nối Lazada lúc này, vui lòng thử lại sau.';

                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'success' => false,
                            'error' => $msg,
                            'request_id' => $link->id,
                            'platform' => $platform,
                        ], 502);
                    }

                    return redirect()->route('dashboard')->with('error', $msg);
                }
            } elseif ($target->isAccessTrade()
                && $this->accessTradeConfig->enabled()
                && $this->accessTradeConfig->hasToken()) {
                // AccessTrade is used ONLY when the admin has explicitly enabled
                // it AND a token is configured. It never intercepts Shopee /
                // TikTok / Lazada / ShopeeFood, which are handled above.
                try {
                    $result = $this->accessTradeLink->createForUrl(
                        $validated['original_url'],
                        $target->campaignId,
                        $user->affiliateSubId() ?? $user->username ?? null,
                    );

                    $link->update([
                        'affiliate_url' => $result['affiliate_url'],
                        'status' => 'completed',
                        'data_source' => 'accesstrade-api',
                    ]);

                    // CellphoneS / other AccessTrade merchants: the affiliate
                    // API returns only a tracking link, so product info is
                    // fetched (best-effort) from the merchant page. A failure
                    // here MUST NOT affect the already-created link.
                    $this->enrichWithProductMetadata($link, $validated['original_url']);

                    // Expected cashback (best-effort): only when the campaign's
                    // advertised max rate is corroborated by its policy.
                    $this->applyAccessTradeEstimate($link, $result['campaign_id'] ?? $target->campaignId);
                } catch (AccessTradeException $e) {
                    $friendly = $this->accessTradeConfig->scrub($e->getUserMessage());

                    $link->update([
                        'status' => 'failed',
                        'notes' => $friendly,
                    ]);

                    Log::warning('[DirectLink] AccessTrade link creation failed', [
                        'url' => $validated['original_url'],
                        'host' => $target->host,
                        'user_id' => $user->id,
                    ]);

                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'success' => false,
                            'error' => $friendly,
                            'request_id' => $link->id,
                            'platform' => $platform,
                        ], 422);
                    }

                    return redirect()->route('dashboard')->with('error', $friendly);
                } catch (\Throwable $e) {
                    $link->update([
                        'status' => 'failed',
                        'notes' => 'Lỗi hệ thống khi tạo link AccessTrade.',
                    ]);

                    Log::warning('[DirectLink] AccessTrade provider failed', [
                        'url' => $validated['original_url'],
                        'error' => $e->getMessage(),
                    ]);

                    $msg = 'Không thể kết nối AccessTrade lúc này, vui lòng thử lại sau.';

                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'success' => false,
                            'error' => $msg,
                            'request_id' => $link->id,
                            'platform' => $platform,
                        ], 502);
                    }

                    return redirect()->route('dashboard')->with('error', $msg);
                }
            } else {
                // Platform recognised but no link-creation handler (Tiki, Khác,
                // spoof host, or AccessTrade not enabled/configured). Must NOT be
                // reported as `completed` with an empty affiliate_url.
                $error = 'Nền tảng chưa được hỗ trợ tạo affiliate link. Vui lòng dán link Shopee, TikTok Shop hoặc Lazada.';

                $link->update([
                    'status' => 'failed',
                    'notes' => $error,
                ]);

                Log::info('[DirectLink] Unsupported platform', [
                    'url' => $validated['original_url'],
                    'host' => $target->host,
                    'reason' => $target->reason,
                    'user_id' => $user->id,
                ]);

                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'error' => $error,
                        'request_id' => $link->id,
                        'platform' => $platform,
                    ], 422);
                }

                return redirect()->route('dashboard')->with('error', $error);
            }
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'request_id' => $link->id,
                'platform' => $platform,
                'affiliate_url' => $link->affiliate_url,
                'status' => $link->status,
            ]);
        }

        return redirect()->route('dashboard')
            ->with('success', 'Đã nhận link. Đang tạo affiliate link...');
    }

    private function cachePlatformMatches(AffiliateCache $cache, string $platform): bool
    {
        $cachePlatform = $cache->getAttribute('platform');

        if ($cachePlatform === null || $cachePlatform === '') {
            return false;
        }

        return strcasecmp((string) $cachePlatform, $platform) === 0;
    }

    private function isPositiveRate($value): bool
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return false;
        }

        return (float) $value > 0;
    }

    private function dispatchProductDataRefresh(int $linkId, string $resolvedUrl, ?int $itemId, string $platform): void
    {
        dispatch(function () use ($linkId, $resolvedUrl, $itemId, $platform) {
            try {
                if (config('app.affiliate_timing')) {
                    Log::info('[CACHE] ProductData URL', [
                        'url' => $resolvedUrl,
                        'item_id' => $itemId,
                    ]);
                }

                $refreshStart = config('app.affiliate_timing') ? microtime(true) : null;
                $productDataService = app(ProductDataService::class);
                $productData = $productDataService->getByUrl($resolvedUrl);
                if ($refreshStart !== null) {
                    Log::info('[CACHE-Timing] Refresh Cache', [
                        'item_id' => $itemId,
                        'elapsed_ms' => (int) ((microtime(true) - $refreshStart) * 1000),
                    ]);
                }

                if (($productData['success'] ?? false)) {
                    $commission = (float) ($productData['commission'] ?? 0);
                    $price = (float) ($productData['product_price'] ?? 0);
                    $cashbackCalculator = app(CashbackCalculator::class);
                    $cashback = $cashbackCalculator->calculate($commission, $price);

                    $update = [
                        'item_id' => $productData['item_id'],
                        'shop_id' => $productData['shop_id'],
                        'estimated_cashback' => $commission,
                        'user_estimated_cashback' => $cashback['user_estimated_cashback'],
                        'cashback_rate' => $cashback['cashback_rate'],
                        'product_name' => $productData['product_name'],
                        'product_price' => $productData['product_price'],
                        'product_link' => $productData['product_link'],
                        'seller_commission' => $productData['seller_commission'],
                        'shopee_commission' => $productData['shopee_commission'],
                        'rating' => $productData['rating'],
                        'product_image' => $productData['product_image'],
                        'shop_name' => $productData['shop_name'],
                        'sales' => $productData['sales'],
                        'is_xtra' => $productData['is_xtra'],
                        'data_source' => $productData['data_source'],
                        'status' => 'completed',
                    ];

                    $shopeeRateSrc = $productData['shopee_rate_source'] ?? null;
                    $shopeeRate = $productData['shopee_commission_rate'] ?? null;
                    $sellerRate = $productData['seller_commission_rate'] ?? null;
                    if ($shopeeRate !== null && $shopeeRateSrc !== null && $shopeeRateSrc !== 'none') {
                        $update['shopee_commission_rate_at_create'] = $shopeeRate;
                        $update['commission_rate_source'] = 'product-data';
                        $update['commission_rate_captured_at'] = now();
                        $update['commission_rate_status'] = 'available';
                    }
                    if ($sellerRate !== null && $shopeeRateSrc !== null && $shopeeRateSrc !== 'none') {
                        $update['seller_commission_rate_at_create'] = $sellerRate;
                    } elseif ($sellerRate !== null && empty($update['commission_rate_source'])) {
                        $update['seller_commission_rate_at_create'] = $sellerRate;
                        $update['commission_rate_source'] = 'product-data';
                        $update['commission_rate_captured_at'] = now();
                        $update['commission_rate_status'] = 'available';
                    }

                    LinkRequest::where('id', $linkId)->update($update);

                    $resolvedItemId = $productData['item_id'] ?? $itemId;
                    if ($resolvedItemId) {
                        $cacheService = app(AffiliateCacheService::class);
                        $cachePut = [
                            'platform' => $platform,
                            'shop_id' => $productData['shop_id'],
                            'product_name' => $productData['product_name'],
                            'product_price' => $productData['product_price'],
                            'seller_commission' => $productData['seller_commission'],
                            'shopee_commission' => $productData['shopee_commission'],
                            'estimated_cashback' => $commission,
                            'user_estimated_cashback' => $cashback['user_estimated_cashback'],
                            'cashback_rate' => $cashback['cashback_rate'],
                            'rating' => $productData['rating'],
                            'sales' => $productData['sales'],
                            'product_image' => $productData['product_image'],
                            'product_link' => $productData['product_link'],
                            'shop_name' => $productData['shop_name'],
                            'is_xtra' => $productData['is_xtra'],
                            'data_source' => $productData['data_source'],
                        ];
                        if ($shopeeRate !== null && $shopeeRateSrc !== 'none') {
                            $cachePut['shopee_commission_rate'] = $shopeeRate;
                        }
                        if ($sellerRate !== null && $shopeeRateSrc !== 'none') {
                            $cachePut['seller_commission_rate'] = $sellerRate;
                        }
                        if (($cachePut['shopee_commission_rate'] ?? null) !== null || ($cachePut['seller_commission_rate'] ?? null) !== null) {
                            if ($shopeeRateSrc !== null && $shopeeRateSrc !== 'none') {
                                $cachePut['commission_rate_source'] = $shopeeRateSrc;
                                $cachePut['commission_rate_status'] = 'available';
                                $cachePut['commission_rate_checked_at'] = now();
                            } elseif (empty($cachePut['commission_rate_source'])) {
                                $cachePut['commission_rate_source'] = 'product-data';
                                $cachePut['commission_rate_status'] = 'available';
                                $cachePut['commission_rate_checked_at'] = now();
                            }
                        }
                        $cacheService->put($resolvedItemId, $cachePut);
                    }
                } else {
                    LinkRequest::where('id', $linkId)
                        ->where('status', 'processing')
                        ->update(['status' => 'completed']);
                }
            } catch (\Throwable $e) {
                LinkRequest::where('id', $linkId)
                    ->where('status', 'processing')
                    ->update(['status' => 'completed']);

                Log::warning('[ShopeeLink] afterResponse ProductData failed', [
                    'link_request_id' => $linkId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    /**
     * Chế độ nhanh (Fast Mode) — chỉ dành cho Shopee.
     *
     * Được gọi SAU KHI resolver đã trả về URL landing hợp lệ, nên vẫn dùng
     * CHUNG đúng một quy tắc affiliate URL với Normal Mode
     * (buildAffiliateUrl) và vẫn lưu LinkRequest như Normal Mode.
     *
     * Trả về `shopeedirect_url` (URL sản phẩm Shopee sạch) cùng
     * `affiliate_url` (URL có tracking) để UI hiển thị cả hai nút.
     *
     * Cố ý KHÔNG gọi ở đây:
     *  - AffiliateCacheService::get()  (không cần dữ liệu sản phẩm)
     *  - AffiliateCacheService::put()
     *  - ProductDataService::getByUrl() (HTTP ra ngoài ~0.65-1.04s)
     *  - CashbackCalculator::calculate()
     *  - dispatch(...)->afterResponse()
     */
    private function storeFastShopeeLink(Request $request, LinkRequest $link, string $resolvedUrl, ?int $itemId): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        $shopeeUrl = $this->cleanShopeeProductUrl($resolvedUrl);
        $affiliateUrl = $this->buildAffiliateUrl($resolvedUrl, $user);

        $link->update([
            'item_id' => $itemId,
            'product_link' => $shopeeUrl,
            'affiliate_url' => $affiliateUrl,
            'status' => 'completed',
        ]);

        if (config('app.affiliate_timing')) {
            Log::info('[FastMode] Shopee link created without ProductData', [
                'link_request_id' => $link->id,
                'item_id' => $itemId,
            ]);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'request_id' => $link->id,
                'platform' => $link->platform,
                'affiliate_url' => $affiliateUrl,
                'shopeedirect_url' => $shopeeUrl,
                'item_id' => $itemId,
                'status' => 'completed',
                'fast_mode' => true,
            ]);
        }

        return redirect()->route('dashboard')
            ->with('success', 'Đã tạo affiliate link (chế độ nhanh).');
    }

    /**
     * Best-effort product metadata enrichment for a completed AccessTrade link.
     *
     * Never throws and never overwrites an existing value with null. The
     * affiliate link is already persisted, so metadata problems are silent to
     * the user (logged only).
     */
    private function enrichWithProductMetadata(LinkRequest $link, string $url): void
    {
        try {
            $metadata = $this->productMetadata->fetch($url);
        } catch (\Throwable $e) {
            Log::warning('[DirectLink] Product metadata fetch failed', [
                'link_request_id' => $link->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($metadata === null) {
            return;
        }

        $update = array_filter([
            'product_name' => $metadata['name'],
            'product_image' => $metadata['image'],
            'product_price' => $metadata['price'],
            'product_link' => $url,
        ], fn ($value) => $value !== null);

        if ($update !== []) {
            $link->update($update);
        }
    }

    /**
     * Best-effort expected-cashback for a completed AccessTrade link.
     *
     * AccessTrade exposes no per-product commission, so the campaign's
     * advertised `max_com` is used ONLY when the campaign policy corroborates it
     * as the single commission rate (see
     * {@see AccessTradeCommissionEstimateService}). When it cannot be
     * corroborated the estimate columns stay null so the UI shows "Chưa tính
     * được tiền hoàn dự kiến" instead of a guessed amount. Never throws and
     * never affects the already-completed link.
     */
    private function applyAccessTradeEstimate(LinkRequest $link, ?string $campaignId): void
    {
        try {
            $campaignId = trim((string) $campaignId);
            $price = (float) ($link->product_price ?? 0);

            if ($campaignId === '' || $price <= 0) {
                return;
            }

            $detail = $this->accessTradeCampaigns->detail($campaignId);

            if ($detail === null
                || $detail['approval'] !== 'successful'
                || (string) $detail['status'] !== '1') {
                return;
            }

            $estimate = $this->accessTradeEstimate->estimate(
                $price,
                $detail['max_commission'],
                $detail['commission_policy'],
            );

            if ($estimate === null) {
                return;
            }

            $link->update([
                'estimated_cashback' => $estimate['commission'],
                'cashback_rate' => $estimate['cashback_rate'],
                'user_estimated_cashback' => $estimate['user_estimated_cashback'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[DirectLink] AccessTrade estimate failed', [
                'link_request_id' => $link->id,
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function friendlyTikTokError(TikTokServiceException $e): string
    {
        $rioMsg = strtolower((string) $e->getRioHubMessage());

        if (str_contains($rioMsg, 'not_promotable') || str_contains($rioMsg, 'promotable')) {
            return 'Sản phẩm này hiện không hỗ trợ tạo link affiliate.';
        }

        if ($e->getCode() === 422) {
            return 'Link TikTok Shop không hợp lệ hoặc sản phẩm không thể tạo link affiliate.';
        }

        if ($e->getCode() === 401 || $e->getCode() === 403) {
            return 'Không thể kết nối TikTok lúc này, vui lòng thử lại sau.';
        }

        return 'Không thể tạo affiliate link TikTok. Vui lòng thử lại sau.';
    }

    /**
     * URL sản phẩm Shopee đã bỏ query string — dùng chung cho Normal Mode
     * (buildAffiliateUrl) và Fast Mode (shopee_url) để hai chế độ không lệch nhau.
     */
    private function cleanShopeeProductUrl(string $resolvedUrl): string
    {
        return explode('?', $resolvedUrl)[0];
    }

    private function buildAffiliateUrl(string $resolvedUrl, $user): string
    {
        $affiliateId = Setting::get('affiliate.direct.shopee_affiliate_id', '');
        $cleanUrl = $this->cleanShopeeProductUrl($resolvedUrl);
        $encodedUrl = rawurlencode($cleanUrl);
        $subId = $user->username ?? '';

        return 'https://s.shopee.vn/an_redir'
            .'?origin_link='.$encodedUrl
            .'&affiliate_id='.$affiliateId
            .'&sub_id='.$subId;
    }
}
