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
            'dashboard_strategy' => Setting::get('affiliate.dashboard.strategy', 'direct'),
            'admin_strategy'     => Setting::get('affiliate.admin.strategy', 'extension'),
            'affiliate_id'       => Setting::get('affiliate.direct.shopee_affiliate_id', ''),
            'resolve'            => Setting::get('affiliate.direct.resolve_shortlink', 'true'),
        ];

        return view('admin.affiliate-config.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dashboard_strategy' => 'required|in:extension,direct',
            'admin_strategy'     => 'required|in:extension,direct',
            'affiliate_id'       => 'nullable|string|max:100',
            'resolve'            => 'required|in:true,false',
        ]);

        Setting::set('affiliate.dashboard.strategy', $validated['dashboard_strategy']);
        Setting::set('affiliate.admin.strategy', $validated['admin_strategy']);
        Setting::set('affiliate.direct.shopee_affiliate_id', $validated['affiliate_id'] ?? '');
        Setting::set('affiliate.direct.resolve_shortlink', $validated['resolve']);

        return redirect()->route('admin.affiliate-config.index')
            ->with('success', 'Đã lưu cấu hình.');
    }
}
