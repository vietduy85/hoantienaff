<?php

namespace App\Http\Controllers\Debug;

use App\Http\Controllers\Controller;
use App\Services\AffiliateWorkerClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ShopeeLoginController extends Controller
{
    public function __construct(
        private AffiliateWorkerClient $worker,
    ) {}

    public function index(): View
    {
        $online = $this->worker->ping();
        $stateFile = base_path('affiliate-worker/storage/shopee-state.json');
        $hasSession = file_exists($stateFile);

        return view('debug.shopee-login', compact('online', 'hasSession', 'stateFile'));
    }

    public function sessionTest(): RedirectResponse
    {
        $result = $this->worker->shopeeSessionTest();

        if ($result['success']) {
            return back()->with('status', 'session-valid')
                ->with('message', 'Session valid');
        }

        $message = $result['message'] ?? $result['error'] ?? 'Lỗi không xác định.';
        $status = $result['message'] === 'Session expired' ? 'session-expired' : 'error';

        return back()->with('status', $status)
            ->with('message', $message);
    }

    public function login(): RedirectResponse
    {
        $result = $this->worker->shopeeLogin();

        if ($result['success']) {
            return back()->with('status', 'success-session')
                ->with('message', 'Đã có session hợp lệ.');
        }

        return back()->with('status', $result['action'] ?? 'error')
            ->with('message', $result['message'] ?? $result['error'] ?? 'Lỗi không xác định.');
    }

    public function loginInteractive(): RedirectResponse
    {
        $result = $this->worker->shopeeLoginInteractive();

        if ($result['success']) {
            return back()->with('status', 'success')
                ->with('message', $result['message'] ?? 'Đăng nhập thành công.');
        }

        return back()->with('status', 'error')
            ->with('message', $result['error'] ?? 'Lỗi không xác định.');
    }
}
