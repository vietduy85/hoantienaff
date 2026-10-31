<div data-testid="dashboard-recent-orders">
    <div class="flex items-center justify-between max-[390px]:mb-2 mb-3">
        <h3 class="text-sm font-semibold text-gray-700">📦 Đơn hàng gần nhất</h3>
        <a href="{{ route('orders.index') }}" class="text-xs text-emerald-600 hover:underline">Xem tất cả</a>
    </div>

    @if ($recentOrders->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center">
            <p class="text-sm text-gray-400">Chưa có đơn hàng nào được ghi nhận</p>
        </div>
    @else
        <div class="space-y-1.5">
            @foreach ($recentOrders as $order)
                <a href="{{ route('orders.show', $order->order_id) }}"
                   class="bg-white rounded-xl shadow-sm border border-gray-100 max-[390px]:px-3 px-4 py-2.5 flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-sm text-gray-800 truncate">{{ $order->shop_name ?: $order->platform }}</div>
                        <div class="text-xs text-gray-400">
                            {{ $order->platform }} · {{ $order->ordered_at ? \Illuminate\Support\Carbon::parse($order->ordered_at)->format('d/m/Y') : '—' }}
                            · <span class="{{ $order->affiliate_status === 'Hoàn thành' ? 'text-emerald-600' : ($order->affiliate_status === 'Đã hủy' ? 'text-red-500' : 'text-gray-500') }}">{{ $order->affiliate_status }}</span>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-[11px] text-gray-400">Hoa hồng</div>
                        <div class="text-sm font-semibold {{ (float) $order->total_cashback > 0 ? 'text-emerald-600' : 'text-gray-300' }}">
                            {{ (float) $order->total_cashback > 0 ? number_format((float) $order->total_cashback, 0, ',', '.').' đ' : '—' }}
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
