<?php

namespace App\Http\Controllers;

use App\Models\AffiliateOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $user = auth()->user();
        $search = $request->input('search');
        $platform = $request->input('platform');
        $status = $request->input('status');

        $orders = AffiliateOrderItem::where('user_id', $user->id)
            ->when($search, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('order_id', 'like', "%{$search}%")
                      ->orWhere('username', 'like', "%{$search}%")
                      ->orWhere('shop_name', 'like', "%{$search}%")
                      ->orWhereIn('order_id', function ($q) use ($search) {
                          $q->select('order_id')
                            ->from('affiliate_order_items')
                            ->where('item_name', 'like', "%{$search}%");
                      });
                });
            })
            ->when($platform, function ($q, $platform) {
                $q->where('platform', $platform);
            })
            ->when($status, function ($q, $status) {
                $q->where('affiliate_status', $status);
            })
            ->select([
                'order_id',
                'shop_name',
                'ordered_at',
                'affiliate_status',
                'platform',
                DB::raw('SUM(cashback_amount) as total_cashback'),
                DB::raw('MAX(order_amount) as order_amount'),
                DB::raw('COUNT(*) as item_count'),
                DB::raw('MAX(last_shopee_sync_at) as last_sync_at'),
            ])
            ->groupBy('order_id', 'shop_name', 'ordered_at', 'affiliate_status', 'platform')
            ->orderBy('ordered_at', 'desc')
            ->paginate(15);

        $platforms = AffiliateOrderItem::where('user_id', $user->id)
            ->distinct()
            ->pluck('platform');

        $statuses = AffiliateOrderItem::where('user_id', $user->id)
            ->distinct()
            ->pluck('affiliate_status');

        return view('orders.index', compact('orders', 'platforms', 'statuses', 'search', 'platform', 'status'));
    }

    public function show(Request $request, string $orderId): View
    {
        $user = auth()->user();

        $items = AffiliateOrderItem::where('user_id', $user->id)
            ->where('order_id', $orderId)
            ->orderBy('item_id')
            ->get();

        if ($items->isEmpty()) {
            abort(404);
        }

        $summary = (object) [
            'order_id' => $orderId,
            'shop_name' => $items->first()->shop_name,
            'affiliate_status' => $items->first()->affiliate_status,
            'ordered_at' => $items->first()->ordered_at,
            'platform' => $items->first()->platform,
            'order_amount' => $items->sum('order_amount'),
            'total_cashback' => $items->sum('cashback_amount'),
            'item_count' => $items->count(),
        ];

        return view('orders.show', compact('summary', 'items'));
    }
}
