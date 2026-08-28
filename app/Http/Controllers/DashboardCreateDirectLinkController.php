<?php

namespace App\Http\Controllers;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Services\AffiliateCacheService;
use App\Services\CashbackCalculator;
use App\Services\ProductDataService;
use App\Services\TikTok\TikTokLinkEstimateService;
use App\Services\TikTok\TikTokServiceException;
use App\Services\UrlResolverService;
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
    ) {}

    public function store(Request $request): \Illuminate\Http\JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'original_url' => ['required', 'url', 'max:2048'],
        ]);

        $user = auth()->user();
        $platform = $this->detectPlatform($validated['original_url']);
        $isShopee = str_contains(strtolower($platform), 'shopee');

        $link = LinkRequest::create([
            'user_id' => $user->id,
            'original_url' => $validated['original_url'],
            'platform' => $platform,
            'status' => $isShopee ? 'processing' : 'completed',
        ]);

        if ($isShopee) {
            $resolvedUrl = $this->urlResolver->resolve($validated['original_url']);

            if ($resolvedUrl === null) {
                Log::warning('[Resolver] Fallback to original URL', [
                    'original_url' => $validated['original_url'],
                ]);
                $resolvedUrl = $validated['original_url'];
            }

            $itemId = $this->cacheService->extractItemId($resolvedUrl);
            $cached = $itemId ? $this->cacheService->get($itemId) : null;

            if ($cached) {
                if (config('app.affiliate_timing')) {
                    Log::info('[CACHE]', [
                        'item_id' => $cached->item_id,
                        'status' => 'HIT',
                    ]);
                }

                $link->update([
                    'item_id'                => $cached->item_id,
                    'shop_id'                => $cached->shop_id,
                    'estimated_cashback'     => $cached->estimated_cashback,
                    'user_estimated_cashback' => $cached->user_estimated_cashback,
                    'cashback_rate'          => $cached->cashback_rate,
                    'product_name'           => $cached->product_name,
                    'product_price'          => $cached->product_price,
                    'product_link'           => $cached->product_link,
                    'seller_commission'      => $cached->seller_commission,
                    'shopee_commission'      => $cached->shopee_commission,
                    'rating'                 => $cached->rating,
                    'product_image'          => $cached->product_image,
                    'shop_name'              => $cached->shop_name,
                    'sales'                  => $cached->sales,
                    'is_xtra'                => $cached->is_xtra,
                    'data_source'            => $cached->data_source,
                ]);

                $affiliateUrl = $this->buildAffiliateUrl($resolvedUrl, $user);

                $link->update([
                    'affiliate_url' => $affiliateUrl,
                    'status'        => 'completed',
                ]);
            } else {
                if ($itemId) {
                    $this->cacheService->logMiss($itemId);

                    $link->update(['item_id' => $itemId]);

                    $this->cacheService->put($itemId, []);
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
                            'item_id'               => $productData['item_id'],
                            'shop_id'               => $productData['shop_id'],
                            'estimated_cashback'     => $commission,
                            'user_estimated_cashback' => $cashback['user_estimated_cashback'],
                            'cashback_rate'          => $cashback['cashback_rate'],
                            'product_name'           => $productData['product_name'],
                            'product_price'          => $productData['product_price'],
                            'product_link'           => $productData['product_link'],
                            'seller_commission'      => $productData['seller_commission'],
                            'shopee_commission'      => $productData['shopee_commission'],
                            'rating'                 => $productData['rating'],
                            'product_image'          => $productData['product_image'],
                            'shop_name'              => $productData['shop_name'],
                            'sales'                  => $productData['sales'],
                            'is_xtra'                => $productData['is_xtra'],
                            'data_source'            => $productData['data_source'],
                            'status'                 => 'completed',
                        ]);

                        $resolvedItemId = $productData['item_id'] ?? $itemIdClone;
                        if ($resolvedItemId) {
                            $cacheService = app(AffiliateCacheService::class);
                            $cacheService->put($resolvedItemId, [
                                'shop_id'                => $productData['shop_id'],
                                'product_name'           => $productData['product_name'],
                                'product_price'          => $productData['product_price'],
                                'seller_commission'      => $productData['seller_commission'],
                                'shopee_commission'      => $productData['shopee_commission'],
                                'estimated_cashback'     => $commission,
                                'user_estimated_cashback' => $cashback['user_estimated_cashback'],
                                'cashback_rate'          => $cashback['cashback_rate'],
                                'rating'                 => $productData['rating'],
                                'sales'                  => $productData['sales'],
                                'product_image'          => $productData['product_image'],
                                'product_link'           => $productData['product_link'],
                                'shop_name'              => $productData['shop_name'],
                                'is_xtra'                => $productData['is_xtra'],
                                'data_source'            => $productData['data_source'],
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
                        'notes'  => $e->getRioHubMessage() ?? $e->getMessage(),
                    ]);

                    Log::warning('[DirectLink] TikTok link creation failed', [
                        'url'      => $validated['original_url'],
                        'error'    => $e->getMessage(),
                        'user_id'  => $user->id,
                    ]);

                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'success' => false,
                            'error'   => $this->friendlyTikTokError($e),
                            'request_id' => $link->id,
                            'platform'   => $platform,
                        ], 422);
                    }

                    return redirect()->route('dashboard')
                        ->with('error', $this->friendlyTikTokError($e));
                } catch (\Throwable $e) {
                    $link->update([
                        'status' => 'failed',
                        'notes'  => 'Lỗi hệ thống khi tạo link TikTok.',
                    ]);

                    Log::warning('[DirectLink] TikTok provider failed', [
                        'url'     => $validated['original_url'],
                        'error'   => $e->getMessage(),
                    ]);

                    $msg = 'Không thể kết nối TikTok lúc này, vui lòng thử lại sau.';

                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'success' => false,
                            'error'   => $msg,
                            'request_id' => $link->id,
                            'platform'   => $platform,
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

    private function detectPlatform(string $url): string
    {
        $url = strtolower($url);

        $platforms = [
            'shopee'  => 'Shopee',
            'shp.ee' => 'Shopee',
            'lazada'  => 'Lazada',
            'tiktok'  => 'TikTok Shop',
            'tiki'    => 'Tiki',
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

    private function buildAffiliateUrl(string $resolvedUrl, $user): string
    {
        $affiliateId = Setting::get('affiliate.direct.shopee_affiliate_id', '');
        $cleanUrl = explode('?', $resolvedUrl)[0];
        $encodedUrl = rawurlencode($cleanUrl);
        $subId = $user->username ?? '';

        return 'https://s.shopee.vn/an_redir'
            . '?origin_link=' . $encodedUrl
            . '&affiliate_id=' . $affiliateId
            . '&sub_id=' . $subId;
    }
}
