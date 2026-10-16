<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateDetectRule;
use App\Models\Setting;
use App\Services\AccessTrade\AccessTradeConfig;
use App\Services\Url\CodeDetectRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AffiliateConfigController extends Controller
{
    public function __construct(
        private readonly CodeDetectRules $codeDetectRules,
        private readonly AccessTradeConfig $accessTradeConfig,
    ) {}

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

        $dbRules = AffiliateDetectRule::query()
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $campaignSnapshot = json_decode((string) Setting::get('accesstrade.campaigns_snapshot', '[]'), true);

        $accessTrade = [
            'enabled' => $this->accessTradeConfig->enabled(),
            'has_token' => $this->accessTradeConfig->hasToken(),
            'masked_token' => $this->accessTradeConfig->maskedToken(),
            'base_url' => $this->accessTradeConfig->baseUrl(),
            'last_check_at' => $this->accessTradeConfig->lastCheckAt(),
            'last_check_ok' => $this->accessTradeConfig->lastCheckOk(),
            'last_check_message' => $this->accessTradeConfig->lastCheckMessage(),
            'campaigns' => is_array($campaignSnapshot) ? $campaignSnapshot : [],
        ];

        return view('admin.affiliate-config.index', compact('settings', 'providers', 'dbRules', 'accessTrade'));
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

        $detectRules = $this->codeDetectRules->all();

        $accessTradeReady = $this->accessTradeConfig->enabled() && $this->accessTradeConfig->hasToken();

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
                'method' => $accessTradeReady ? 'AccessTrade API' : '—',
                'status' => $accessTradeReady ? 'configured' : 'not_configured',
                'status_label' => $accessTradeReady ? 'Đã kết nối' : 'Chưa kết nối',
                'methods' => [
                    ['label' => 'AccessTrade API', 'state' => $accessTradeReady ? 'active' : 'not_implemented'],
                ],
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
