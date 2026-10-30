<?php

namespace App\Http\Controllers;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AffiliateLinkService;
use App\Services\ShopeeFashionAlertService;
use App\Services\ShopeeFood\ShopeeFoodAffiliateLinkService;
use App\Services\ShopeeFood\ShopeeFoodPreviewService;
use App\Services\ShopeeFood\ShopeeFoodUrlParser;
use Illuminate\Http\JsonResponse;
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

        return view('dashboard', [
            'pinnedLinks' => $pinnedLinks,
            'recentLinks' => $recentLinks,
            'shopeeFashionAlert' => $this->shopeeFashionAlert($user),
        ]);
    }

    /**
     * Dữ liệu thông báo "cào xu" cho trang chủ, hoặc null khi user đã tắt
     * thông báo hoặc không còn đơn thời trang nào chưa biết.
     *
     * URL đích (href) và URL hiển thị lấy từ cấu hình Admin (bảng settings);
     * mặc định là link cào xu Shopee đang hoạt động.
     *
     * @return array{count: int, destinationUrl: string, displayUrl: string}|null
     */
    private function shopeeFashionAlert(User $user): ?array
    {
        $service = app(ShopeeFashionAlertService::class);

        if (! $service->isEnabled($user)) {
            return null;
        }

        $count = $service->unseenCount($user);

        return $count > 0 ? [
            'count' => $count,
            'destinationUrl' => Setting::shopeeFashionAlertDestinationUrl(),
            'displayUrl' => Setting::shopeeFashionAlertDisplayUrl(),
        ] : null;
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'original_url' => ['required', 'string', 'max:2048'],
        ]);

        $originalUrl = (string) $request->input('original_url');

        // Raw vnnow-food links use a hyphenated host that Laravel's strict
        // `url` rule rejects, so ShopeeFood hosts are accepted up-front and
        // normalized later; every other URL keeps the strict validation.
        if ($this->isShopeeFoodUrl($originalUrl)) {
            return $this->storeViaShopeeFoodDeepLink($request, $originalUrl);
        }

        $request->validate([
            'original_url' => ['url'],
        ]);

        $strategy = Setting::extensionShortlinkEnabled()
            ? Setting::get('affiliate.dashboard.strategy', 'direct')
            : 'direct';

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

    private function storeViaShopeeFoodDeepLink(Request $request, string $originalUrl): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        $link = LinkRequest::create([
            'user_id' => $user->id,
            'original_url' => $originalUrl,
            'platform' => 'ShopeeFood',
            'status' => 'processing',
        ]);

        $pipeline = $this->shopeeFoodAffiliateLink->resolvePipeline($originalUrl);
        $restaurantId = $pipeline['restaurant_id'];
        $fastMode = $request->boolean('fast_mode');

        $affiliateUrl = $restaurantId !== null
            ? $this->shopeeFoodAffiliateLink->buildAffiliateUrl($restaurantId, $user->affiliateSubId())
            : null;

        if ($affiliateUrl === null) {
            Log::warning('[ShopeeFood] Deep link generation failed', [
                'link_request_id' => $link->id,
                'original_url' => $originalUrl,
                'restaurant_id' => $restaurantId,
            ]);

            $link->update([
                'status' => 'failed',
                'notes' => 'Không thể tạo affiliate link ShopeeFood.',
            ]);
        } else {
            $link->update([
                'affiliate_url' => $affiliateUrl,
                'status' => 'completed',
            ]);
        }

        // Xem trước (tên quán/ảnh) là việc độc lập: lỗi không ảnh hưởng link đã tạo.
        // Chế độ nhanh: bỏ hẳn.
        if (! $fastMode) {
            try {
                $this->shopeeFoodPreview->preview($link, $originalUrl, $restaurantId);
            } catch (\Throwable $e) {
                Log::warning('[ShopeeFood] preview failed (best-effort)', ['link_request_id' => $link->id, 'error' => $e->getMessage()]);
            }
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'request_id' => $link->id,
                'platform' => $link->platform,
                'status' => $link->status,
                'affiliate_url' => $link->affiliate_url,
            ] + ($fastMode ? ['fast_mode' => true] : []));
        }

        return redirect()->route('dashboard')
            ->with('success', 'Đã tạo affiliate link ShopeeFood.');
    }

    private function isShopeeFoodUrl(string $url): bool
    {
        return ShopeeFoodUrlParser::isShopeeFoodUrl($url);
    }
}
