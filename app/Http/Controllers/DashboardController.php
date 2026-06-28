<?php

namespace App\Http\Controllers;

use App\Models\LinkRequest;
use App\Services\ProductDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ProductDataService $productData,
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
            $productData = $this->productData->getByUrl($validated['original_url']);

            if (($productData['success'] ?? false)) {
                $link->update([
                    'item_id'           => $productData['item_id'],
                    'shop_id'           => $productData['shop_id'],
                    'estimated_cashback' => $productData['commission'],
                    'product_name'      => $productData['product_name'],
                    'product_price'     => $productData['product_price'],
                    'product_link'      => $productData['product_link'],
                    'seller_commission' => $productData['seller_commission'],
                    'shopee_commission' => $productData['shopee_commission'],
                    'rating'            => $productData['rating'],
                    'product_image'     => $productData['product_image'],
                    'shop_name'         => $productData['shop_name'],
                    'sales'             => $productData['sales'],
                    'is_xtra'           => $productData['is_xtra'],
                    'data_source'       => $productData['data_source'],
                ]);
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
