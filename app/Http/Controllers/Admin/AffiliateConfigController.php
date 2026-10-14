<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AffiliateConfigController extends Controller
{
    public function index(): View
    {
        $settings = [
            'extension_enabled' => Setting::extensionShortlinkEnabled() ? 'true' : 'false',
            'dashboard_strategy' => Setting::get('affiliate.dashboard.strategy', 'direct'),
            'admin_strategy' => Setting::get('affiliate.admin.strategy', 'extension'),
            'affiliate_id' => Setting::get('affiliate.direct.shopee_affiliate_id', ''),
            'resolve' => Setting::get('affiliate.direct.resolve_shortlink', 'true'),
        ];

        $providers = $this->buildProviders($settings);

        return view('admin.affiliate-config.index', compact('settings', 'providers'));
    }

    /**
     * Read-only trạng thái nhà cung cấp / phương thức tạo link cho tab
     * "Connect Partner AFF". CHỈ trả về nhãn trạng thái + boolean cấu hình,
     * KHÔNG trả về giá trị API key/secret/cookie. Không đọc/ghi gì ngoài
     * settings hiện có và config services (chỉ kiểm tra có/không).
     *
     * @param  array<string, string>  $settings
     * @return array<int, array<string, mixed>>
     */
    private function buildProviders(array $settings): array
    {
        $extensionEnabled = $settings['extension_enabled'] === 'true';
        $shopeeMethod = $extensionEnabled && $settings['dashboard_strategy'] === 'extension'
            ? 'Extension Worker'
            : 'Direct Link';

        $shopeeAffiliateIdSet = trim((string) $settings['affiliate_id']) !== '';
        $shopeeReady = $shopeeMethod === 'Extension Worker' || $shopeeAffiliateIdSet;

        $riohubReady = filled(config('services.riohub.base_url'))
            && filled(config('services.riohub.api_key'));

        $lazadaReady = filled(config('services.lazada.app_key'))
            && filled(config('services.lazada.user_token'));

        $shopeeFoodReady = filled(config('services.shopeefood.cookie'))
            || filled(config('services.shopeefood.base_url'));

        $detectRules = $this->detectRules();

        return [
            [
                'key' => 'shopee',
                'detect' => $detectRules['shopee'],
                'name' => 'Shopee',
                'method' => $shopeeMethod,
                'status' => $shopeeReady ? 'configured' : 'incomplete',
                'status_label' => $shopeeReady ? 'Đã cấu hình' : 'Thiếu cấu hình',
                'methods' => [
                    ['label' => 'Direct Link', 'state' => $shopeeMethod === 'Direct Link' ? 'active' : 'available'],
                    ['label' => 'Extension Worker', 'state' => $shopeeMethod === 'Extension Worker' ? 'active' : 'available'],
                    ['label' => 'AccessTrade API', 'state' => 'not_implemented'],
                ],
            ],
            [
                'key' => 'tiktok',
                'detect' => $detectRules['tiktok'],
                'name' => 'TikTok Shop',
                'method' => 'Direct Link (RioHub API)',
                'status' => $riohubReady ? 'configured' : 'incomplete',
                'status_label' => $riohubReady ? 'Đã cấu hình' : 'Thiếu cấu hình',
                'methods' => [
                    ['label' => 'Direct Link (RioHub API)', 'state' => 'active'],
                    ['label' => 'AccessTrade API', 'state' => 'not_implemented'],
                ],
            ],
            [
                'key' => 'lazada',
                'detect' => $detectRules['lazada'],
                'name' => 'Lazada',
                'method' => 'Direct Link (Lazada API)',
                'status' => $lazadaReady ? 'configured' : 'incomplete',
                'status_label' => $lazadaReady ? 'Đã cấu hình' : 'Thiếu cấu hình',
                'methods' => [
                    ['label' => 'Direct Link (Lazada API)', 'state' => 'active'],
                    ['label' => 'AccessTrade API', 'state' => 'not_implemented'],
                ],
            ],
            [
                'key' => 'shopeefood',
                'detect' => $detectRules['shopeefood'],
                'name' => 'ShopeeFood',
                'method' => 'ShopeeFood Deep Link',
                'status' => $shopeeFoodReady ? 'configured' : 'incomplete',
                'status_label' => $shopeeFoodReady ? 'Đã cấu hình' : 'Thiếu cấu hình',
                'methods' => [
                    ['label' => 'ShopeeFood Deep Link', 'state' => 'active'],
                    ['label' => 'AccessTrade API', 'state' => 'not_implemented'],
                ],
            ],
            [
                'key' => 'accesstrade',
                'detect' => $detectRules['accesstrade'],
                'name' => 'AccessTrade',
                'method' => '—',
                'status' => 'not_configured',
                'status_label' => 'Chưa kết nối',
                'methods' => [
                    ['label' => 'AccessTrade API', 'state' => 'not_implemented'],
                ],
            ],
        ];
    }

    /**
     * Chuỗi/hostname nhận diện URL THỰC TẾ của từng nhà cung cấp, tổng hợp
     * từ code đang chạy (không suy đoán). Mỗi rule gồm:
     *   - pattern : chuỗi hoặc hostname được so khớp
     *   - match   : 'substring' | 'hostname'
     *   - scope   : file/hàm sử dụng rule
     *   - source  : 'code' | 'config' | 'database'
     *   - method  : phương thức tạo link được gọi sau khi detect
     *   - status  : 'active' | 'flow_only' | 'diagnostic' | 'legacy'
     *
     * KHÔNG chứa API key/token/secret. Read-only.
     *
     * @return array<string, array{rules: array<int, array<string, string>>, note: ?string}>
     */
    private function detectRules(): array
    {
        return [
            'shopee' => [
                'rules' => [
                    [
                        'pattern' => 'shopee',
                        'match' => 'substring',
                        'scope' => 'Dashboard Direct/Extension + Admin — detectPlatform()',
                        'source' => 'code',
                        'method' => 'Direct Link / Extension Worker',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'shp.ee',
                        'match' => 'substring',
                        'scope' => 'Dashboard Direct/Extension + Admin — detectPlatform()',
                        'source' => 'code',
                        'method' => 'Direct Link / Extension Worker',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 's.shopee.vn, vn.shp.ee, s.shp.ee, shope.ee',
                        'match' => 'hostname',
                        'scope' => 'UrlResolverService::isShortLink() — resolve link rút gọn',
                        'source' => 'code',
                        'method' => 'Direct Link (→ s.shopee.vn/an_redir)',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'shopee.vn',
                        'match' => 'hostname',
                        'scope' => 'UrlResolverService::isShopeeLanding() — xác thực landing',
                        'source' => 'code',
                        'method' => 'Direct Link',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'shopee',
                        'match' => 'substring',
                        'scope' => 'ProviderFactory::detectPlatform() — /debug/provider + BenchmarkAffiliate',
                        'source' => 'code',
                        'method' => 'Providers/AffiliateService (không dùng ở luồng thật)',
                        'status' => 'diagnostic',
                    ],
                ],
                'note' => '4 nguồn thuộc luồng tạo link thật (3 bản detectPlatform trùng nhau + resolver). Chuỗi con khớp cả shopee.vn.example.com.',
            ],
            'tiktok' => [
                'rules' => [
                    [
                        'pattern' => 'tiktok',
                        'match' => 'substring',
                        'scope' => 'Dashboard Direct Link — detectPlatform() (đặt nhãn platform)',
                        'source' => 'code',
                        'method' => '— (chỉ gán nhãn)',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'tiktok',
                        'match' => 'substring',
                        'scope' => 'Dashboard Direct Link — nhánh non-Shopee: str_contains(original_url, "tiktok")',
                        'source' => 'code',
                        'method' => 'Direct Link (RioHub API)',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'tiktok',
                        'match' => 'substring',
                        'scope' => 'ProviderFactory::detectPlatform() — /debug/provider + BenchmarkAffiliate',
                        'source' => 'code',
                        'method' => 'TikTokProvider (không dùng ở luồng thật)',
                        'status' => 'diagnostic',
                    ],
                ],
                'note' => 'Detect 2 lần trong cùng request (nhãn + dispatch); đều là substring trên toàn URL nên evil.com/tiktok cũng khớp.',
            ],
            'lazada' => [
                'rules' => [
                    [
                        'pattern' => 'lazada',
                        'match' => 'substring',
                        'scope' => 'Dashboard Direct Link — detectPlatform() + nhánh non-Shopee',
                        'source' => 'code',
                        'method' => 'Direct Link (Lazada API)',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'lazada.vn, lazada.sg, lazada.com.my, lazada.co.th, lazada.com.ph, lazada.co.id, lazada.shop',
                        'match' => 'hostname',
                        'scope' => 'LazadaUrlParser::DOMAINS / isLazadaHost() — validate trong LazadaLinkEstimateService',
                        'source' => 'code',
                        'method' => 'Direct Link (Lazada API)',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'lazada',
                        'match' => 'substring',
                        'scope' => 'ProviderFactory::detectPlatform() — /debug/provider + BenchmarkAffiliate',
                        'source' => 'code',
                        'method' => 'LazadaProvider (không dùng ở luồng thật)',
                        'status' => 'diagnostic',
                    ],
                ],
                'note' => '2 nguồn trong luồng thật: substring (dispatch) + hostname (validate). Substring khớp cả lazada.evil.com.',
            ],
            'shopeefood' => [
                'rules' => [
                    [
                        'pattern' => 'shopeefood.vn (+ subdomain)',
                        'match' => 'hostname',
                        'scope' => 'ShopeeFoodUrlParser::isShopeeFoodHost() → DashboardController::isShopeeFoodUrl() (chạy TRƯỚC khi chọn strategy)',
                        'source' => 'code',
                        'method' => 'ShopeeFood Deep Link',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'spf.shopee.vn',
                        'match' => 'hostname',
                        'scope' => 'ShopeeFoodUrlParser::SPF_HOST + ShopeeFoodAffiliateResolver — resolve short link',
                        'source' => 'code',
                        'method' => 'ShopeeFood Deep Link',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'shopeefood.shopee.vn',
                        'match' => 'hostname',
                        'scope' => 'ShopeeFoodUrlParser::AFFILIATE_HOST + ShopeeFoodOpenGraphService',
                        'source' => 'code',
                        'method' => 'ShopeeFood Deep Link',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'shopeefood.shopee.vnnow-food',
                        'match' => 'hostname',
                        'scope' => 'ShopeeFoodUrlParser::VNNOW_HOST — normalize về host chuẩn',
                        'source' => 'code',
                        'method' => 'ShopeeFood Deep Link',
                        'status' => 'active',
                    ],
                ],
                'note' => 'BẮT BUỘC kiểm tra ShopeeFood TRƯỚC Shopee: chuỗi con "shopee" khớp cả "shopeefood"; đảo thứ tự sẽ nhận nhầm ShopeeFood thành Shopee.',
            ],
            'accesstrade' => [
                'rules' => [],
                'note' => 'Chưa cấu hình domain merchant/campaign. Không có rule detect nào trong code.',
            ],
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'extension_enabled' => 'required|in:true,false',
            'dashboard_strategy' => 'required|in:extension,direct',
            'admin_strategy' => 'required|in:extension,direct',
            'affiliate_id' => 'nullable|string|max:100',
            'resolve' => 'required|in:true,false',
        ]);

        Setting::set(Setting::EXTENSION_SHORTLINK_ENABLED, $validated['extension_enabled']);

        // Extension Worker off: both short-link flows are forced onto Direct Link
        // so stored config matches the effective runtime behaviour.
        $strategy = $validated['extension_enabled'] === 'true'
            ? ['dashboard' => $validated['dashboard_strategy'], 'admin' => $validated['admin_strategy']]
            : ['dashboard' => 'direct', 'admin' => 'direct'];

        Setting::set('affiliate.dashboard.strategy', $strategy['dashboard']);
        Setting::set('affiliate.admin.strategy', $strategy['admin']);
        Setting::set('affiliate.direct.shopee_affiliate_id', $validated['affiliate_id'] ?? '');
        Setting::set('affiliate.direct.resolve_shortlink', $validated['resolve']);

        return redirect()->route('admin.affiliate-config.index')
            ->with('success', 'Đã lưu cấu hình.');
    }
}
