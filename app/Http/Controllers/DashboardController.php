<?php

namespace App\Http\Controllers;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Services\AffiliateLinkService;
use App\Services\ShopeeFood\ShopeeFoodAffiliateLinkService;
use App\Services\ShopeeFood\ShopeeFoodPreviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly AffiliateLinkService $affiliateLinkService,
        private readonly ShopeeFoodPreviewService $shopeeFoodPreview,
        private readonly ShopeeFoodAffiliateLinkService $shopeeFoodAffiliateLink,
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

        if ($this->isShopeeFoodUrl($validated['original_url'])) {
            return $this->storeViaShopeeFoodDeepLink($request, $validated['original_url']);
        }

        $strategy = Setting::get('affiliate.dashboard.strategy', 'direct');

        if ($strategy === 'direct') {
            $controller = app(DashboardCreateDirectLinkController::class);
        } else {
            $controller = app(DashboardCreateExtensionLinkController::class);
        }

        return $controller->store($request);
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

    private function storeViaShopeeFoodDeepLink(Request $request, string $originalUrl): \Illuminate\Http\JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        $link = LinkRequest::create([
            'user_id'      => $user->id,
            'original_url' => $originalUrl,
            'platform'     => 'ShopeeFood',
            'status'       => 'processing',
        ]);

        $this->shopeeFoodPreview->preview($link, $originalUrl);

        $affiliateUrl = $this->shopeeFoodAffiliateLink->generateAffiliateUrl(
            $originalUrl,
            $user->affiliateSubId(),
        );

        if ($affiliateUrl === null) {
            Log::warning('[ShopeeFood] Deep link generation failed', [
                'link_request_id' => $link->id,
                'original_url'    => $originalUrl,
            ]);

            $link->update([
                'status' => 'failed',
                'notes'  => 'Không thể tạo affiliate link ShopeeFood.',
            ]);
        } else {
            $link->update([
                'affiliate_url' => $affiliateUrl,
                'status'        => 'completed',
            ]);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success'       => true,
                'request_id'    => $link->id,
                'platform'      => $link->platform,
                'status'        => $link->status,
                'affiliate_url' => $link->affiliate_url,
            ]);
        }

        return redirect()->route('dashboard')
            ->with('success', 'Đã tạo affiliate link ShopeeFood.');
    }

    private function isShopeeFoodUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_ends_with($host, 'shopeefood.vn')
            || str_ends_with($host, 'shopeefood.shopee.vn')
            || $host === 'spf.shopee.vn'
            || str_ends_with($host, '.spf.shopee.vn');
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
}
