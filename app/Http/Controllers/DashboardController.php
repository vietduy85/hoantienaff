<?php

namespace App\Http\Controllers;

use App\Models\LinkRequest;
use App\Services\AffiliateCacheService;
use App\Services\CashbackCalculator;
use App\Services\ProductDataService;
use App\Services\UrlResolverService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ProductDataService $productData,
        private readonly CashbackCalculator $cashbackCalculator,
        private readonly AffiliateCacheService $cacheService,
        private readonly UrlResolverService $urlResolver,
    ) {}

    public function index(): View
    {
        $user = auth()->user();

        $pinnedLinks = LinkRequest::forUser($user)
            ->pinned()
            ->latest('pinned_at')
            ->limit(5)
            ->get();

        $recentLinks = LinkRequest::forUser($user)
            ->latest()
            ->limit(5)
            ->get();

        return view('dashboard', compact('pinnedLinks', 'recentLinks'));
    }

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
            'status' => $isShopee ? 'pending' : 'completed',
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

                $status = $cached->affiliate_url ? 'completed' : 'pending';
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
                    'affiliate_url'          => $cached->affiliate_url,
                    'status'                 => $status,
                ]);
            } else {
                if ($itemId) {
                    $this->cacheService->logMiss($itemId);

                    $link->update(['item_id' => $itemId]);

                    $this->cacheService->put($itemId, []);
                }

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
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'request_id' => $link->id,
                'platform' => $platform,
            ]);
        }

        return redirect()->route('dashboard')
            ->with('success', 'Đã nhận link. Đang tạo affiliate link...');
    }

    public function togglePin(LinkRequest $linkRequest): RedirectResponse
    {
        $user = auth()->user();

        if ($linkRequest->user_id !== $user->id) {
            abort(403);
        }

        if ($linkRequest->is_pinned) {
            $linkRequest->update([
                'is_pinned' => false,
                'pinned_at' => null,
            ]);
        } else {
            $pinnedCount = LinkRequest::forUser($user)->pinned()->count();

            if ($pinnedCount >= 5) {
                return redirect()->route('dashboard')
                    ->with('error', 'Bạn chỉ có thể ghim tối đa 5 link.');
            }

            $linkRequest->update([
                'is_pinned' => true,
                'pinned_at' => now(),
            ]);
        }

        return redirect()->route('dashboard');
    }

    private function detectPlatform(string $url): string
    {
        $url = strtolower($url);

        $platforms = [
            'shopee'  => 'Shopee',
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
}
