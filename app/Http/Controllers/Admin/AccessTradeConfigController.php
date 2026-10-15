<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AccessTrade\AccessTradeCampaignService;
use App\Services\AccessTrade\AccessTradeConfig;
use App\Services\AccessTrade\AccessTradeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * AccessTrade API connection configuration (Phase C) + connection check.
 *
 * The token is stored encrypted and never echoed back. A single real read
 * request ("Kiểm tra kết nối") is the ONLY way the status becomes Connected —
 * merely saving a token does not claim success.
 */
class AccessTradeConfigController extends Controller
{
    public function __construct(
        private readonly AccessTradeConfig $config,
        private readonly AccessTradeCampaignService $campaigns,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'in:true,false'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'token' => ['nullable', 'string', 'max:500'],
            'clear_token' => ['nullable', 'in:0,1'],
        ]);

        $this->config->setEnabled($validated['enabled'] === 'true');
        $this->config->setBaseUrl($validated['base_url'] ?? '');

        if (($validated['clear_token'] ?? '0') === '1') {
            $this->config->setToken(null);
        } elseif (filled($validated['token'] ?? null)) {
            $this->config->setToken($validated['token']);
        }

        $message = 'Đã lưu cấu hình AccessTrade.'
            .($this->config->hasToken() ? ' Nhấn "Kiểm tra kết nối" để xác nhận token hoạt động.' : '');

        return redirect()->route('admin.affiliate-config.index', ['tab' => 'partner'])
            ->with('success', $message);
    }

    public function test(): RedirectResponse
    {
        $redirect = redirect()->route('admin.affiliate-config.index', ['tab' => 'partner']);

        if (! $this->config->enabled()) {
            return $redirect->with('error', 'AccessTrade đang tắt. Hãy bật kết nối trước khi kiểm tra.');
        }

        if (! $this->config->hasToken()) {
            return $redirect->with('error', 'Chưa cấu hình AccessTrade API key.');
        }

        try {
            $campaigns = $this->campaigns->campaigns();

            $this->config->recordCheck(true, sprintf('Kết nối thành công. %d campaign đã duyệt.', count($campaigns)));

            Setting::set('accesstrade.campaigns_snapshot', json_encode(
                array_slice($campaigns, 0, 200),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return $redirect->with('success', sprintf('Kết nối AccessTrade thành công. %d campaign đã duyệt.', count($campaigns)));
        } catch (AccessTradeException $e) {
            $this->config->recordCheck(false, $this->config->scrub($e->getUserMessage()));

            return $redirect->with('error', $this->config->scrub($e->getUserMessage()));
        } catch (Throwable $e) {
            $this->config->recordCheck(false, $this->config->scrub($e->getMessage()));

            return $redirect->with('error', 'Không kiểm tra được kết nối AccessTrade.');
        }
    }
}
