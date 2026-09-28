<?php

namespace App\Http\Controllers;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Services\AffiliateCacheService;
use App\Services\CashbackCalculator;
use App\Services\Lazada\LazadaException;
use App\Services\Lazada\LazadaLinkEstimateService;
use App\Services\ProductDataService;
use App\Services\TikTok\TikTokLinkEstimateService;
use App\Services\TikTok\TikTokServiceException;
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
    ) {}

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'original_url' => ['required', 'url', 'max:2048'],
        ]);

        $user = auth()->user();
        $platform = $this->detectPlatform($validated['original_url']);
        $isShopee = str_contains(strtolower($platform), 'shopee');

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

            $cached = $itemId ? $this->cacheService->get($itemId) : null;

            if ($cached) {
                if (config('app.affiliate_timing')) {
                    Log::info('[CACHE]', [
                        'item_id' => $cached->item_id,
                        'status' => 'HIT',
                    ]);
                }

                $link->update([
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
                ]);

                $affiliateUrl = $this->buildAffiliateUrl($resolvedUrl, $user);

                $link->update([
                    'affiliate_url' => $affiliateUrl,
                    'status' => 'completed',
                ]);
            } else {
                if ($itemId) {
                    $this->cacheService->logMiss($itemId);

                    $link->update(['item_id' => $itemId]);
                }

                $affiliateUrl = $this->buildAffiliateUrl($resolvedUrl, $user);

                $link->update([
                    'affiliate_url' => $affiliateUrl,
                ]);

                $linkId = $link->id;
                $resolvedUrlClone = $resolvedUrl;
                $itemIdClone = $itemId;

                dispatch(function () use ($resolvedUrlClone, $itemIdClone, $linkId) {
                    if (config('app.affiliate_timing')) {
                        Log::info('[CACHE] ProductData URL', [
                            'url' => $resolvedUrlClone,
                            'item_id' => $itemIdClone,
                        ]);
                    }

                    $refreshStart = config('app.affiliate_timing') ? microtime(true) : null;
                    $productDataService = app(ProductDataService::class);
                    $productData = $productDataService->getByUrl($resolvedUrlClone);
                    if ($refreshStart !== null) {
                        Log::info('[CACHE-Timing] Refresh Cache', [
                            'item_id' => $itemIdClone,
                            'elapsed_ms' => (int) ((microtime(true) - $refreshStart) * 1000),
                        ]);
                    }

                    if (($productData['success'] ?? false)) {
                        $commission = (float) ($productData['commission'] ?? 0);
                        $price = (float) ($productData['product_price'] ?? 0);
                        $cashbackCalculator = app(CashbackCalculator::class);
                        $cashback = $cashbackCalculator->calculate($commission, $price);

                        LinkRequest::where('id', $linkId)->update([
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
                        ]);

                        $resolvedItemId = $productData['item_id'] ?? $itemIdClone;
                        if ($resolvedItemId) {
                            $cacheService = app(AffiliateCacheService::class);
                            $cacheService->put($resolvedItemId, [
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
                            ]);
                        }
                    }
                })->afterResponse();
            }
        } else {
            if (str_contains(strtolower($validated['original_url']), 'tiktok')) {
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
            } elseif (str_contains(strtolower($validated['original_url']), 'lazada')) {
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

    private function detectPlatform(string $url): string
    {
        $url = strtolower($url);

        $platforms = [
            'shopee' => 'Shopee',
            'shp.ee' => 'Shopee',
            'lazada' => 'Lazada',
            'tiktok' => 'TikTok Shop',
            'tiki' => 'Tiki',
        ];

        foreach ($platforms as $domain => $name) {
            if (str_contains($url, $domain)) {
                return $name;
            }
        }

        return 'Khác';
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
