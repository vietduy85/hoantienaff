<?php

namespace App\Http\Controllers;

use App\Models\AffiliateOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function index(Request $request): View
    {
        $user = auth()->user();

        $balances = AffiliateOrderItem::query()
            ->where('user_id', $user->id)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN affiliate_status = 'Hoàn thành' THEN cashback_amount ELSE 0 END), 0) as available,
                COALESCE(SUM(CASE WHEN affiliate_status = 'Đang chờ xử lý' THEN cashback_amount ELSE 0 END), 0) as pending,
                COALESCE(SUM(CASE WHEN affiliate_status = 'Đã thanh toán' THEN cashback_amount ELSE 0 END), 0) as paid
            ")
            ->first();

        return view('wallet.index', [
            'available' => (float) $balances->available,
            'pending' => (float) $balances->pending,
            'paid' => (float) $balances->paid,
        ]);
    }
}
