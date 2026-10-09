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

        return view('admin.affiliate-config.index', compact('settings'));
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
