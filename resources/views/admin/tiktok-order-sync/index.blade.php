<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
            {{ __('Đồng bộ đơn hàng TikTok') }}
        </h2>
    </x-slot>

    <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">

        @if (session('tiktok_sync_error'))
            <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-sm">
                {{ session('tiktok_sync_error') }}
            </div>
        @endif

        @if (session('tiktok_sync_result'))
            @php $res = session('tiktok_sync_result'); @endphp
            <div class="rounded-2xl p-4 text-sm {{ $res['success'] ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-amber-50 border border-amber-200 text-amber-800' }}">
                <div class="font-semibold mb-2">{{ $res['message'] }}</div>
                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1">
                    <div class="flex justify-between"><dt class="text-gray-500">Orders fetched</dt><dd class="font-mono">{{ $res['orders_fetched'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Items fetched</dt><dd class="font-mono">{{ $res['items_fetched'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Inserted</dt><dd class="font-mono">{{ $res['inserted'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Updated</dt><dd class="font-mono">{{ $res['updated'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Skipped</dt><dd class="font-mono">{{ $res['skipped'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Errors</dt><dd class="font-mono">{{ $res['errors'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Wallet credits</dt><dd class="font-mono">{{ $res['wallet_credits'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Wallet reversals</dt><dd class="font-mono">{{ $res['wallet_reversals'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Duration</dt><dd class="font-mono">{{ $res['duration'] }}s</dd></div>
                </dl>
            </div>
        @endif

        {{-- Form --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-1">Đồng bộ từ RioHub</h3>
            <p class="text-sm text-gray-500 mb-4">
                Lấy toàn bộ đơn hàng TikTok từ RioHub, cập nhật vào kho đơn hàng, tính hoa hồng cho đơn đã
                thanh toán (SETTLED) và xử lý hoàn tiền (REFUNDED). Tự động chạy mỗi 3 giờ — nhấn nút bên dưới
                để đồng bộ ngay lập tức.
            </p>
            <div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 mb-4 text-sm text-gray-600">
                <span class="font-medium text-gray-700">Lần đồng bộ TikTok gần nhất:</span>
                <span class="font-mono">{{ $lastSyncAt ? \Illuminate\Support\Carbon::parse($lastSyncAt)->format('d/m/Y H:i:s') : 'Chưa có' }}</span>
            </div>

            <form method="POST" action="{{ route('admin.tiktok-order-sync.sync') }}" class="space-y-4"
                  onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').textContent = 'Đang đồng bộ đơn hàng TikTok...';">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Từ ngày (hiển thị)</label>
                        <input type="date" name="from" value="{{ old('from') }}"
                               class="w-full rounded-xl border-gray-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Đến ngày (hiển thị)</label>
                        <input type="date" name="to" value="{{ old('to') }}"
                               class="w-full rounded-xl border-gray-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                </div>

                @error('from')<div class="text-xs text-red-600">{{ $message }}</div>@enderror
                @error('to')<div class="text-xs text-red-600">{{ $message }}</div>@enderror

                <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-sm text-amber-800">
                    Đơn đã SETTLED sẽ được tính hoa hồng vào ví người dùng đã được phân bổ. Đơn chưa xác định chủ
                    sẽ gán về tài khoản mặc định <code class="font-mono">tintuctonghop103</code>.
                </div>

                <div>
                    <button type="submit"
                            class="inline-flex items-center justify-center px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-300">
                        Đồng bộ đơn hàng TikTok
                    </button>
                </div>
            </form>
        </div>

        {{-- Stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng đơn TikTok</div>
                <div class="text-2xl font-bold text-gray-800">{{ number_format($stats['total'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Đã hoàn thành</div>
                <div class="text-2xl font-bold text-emerald-600">{{ number_format($stats['settled'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Đã hủy / hoàn</div>
                <div class="text-2xl font-bold text-red-600">{{ number_format($stats['refunded'], 0, ',', '.') }}</div>
            </div>
        </div>

        {{-- Recent orders --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-3">Đơn TikTok gần đây</h3>

            @if ($recentOrders->isEmpty())
                <p class="text-sm text-gray-500">Chưa có đơn TikTok nào được đồng bộ.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-gray-400 uppercase tracking-wide">
                            <tr>
                                <th class="py-2 pr-4">Order ID</th>
                                <th class="py-2 pr-4">Sản phẩm</th>
                                <th class="py-2 pr-4">Trạng thái</th>
                                <th class="py-2 pr-4 text-right">Hoa hồng (net)</th>
                                <th class="py-2 pr-4 text-right">Hoàn tiền</th>
                                <th class="py-2 pr-4">Người dùng</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-700">
                            @foreach ($recentOrders as $order)
                                <tr class="border-t border-gray-100">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $order->order_id }}</td>
                                    <td class="py-2 pr-4 max-w-[16rem] truncate">{{ $order->item_name }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($order->affiliate_status === 'Hoàn thành')
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">Hoàn thành</span>
                                        @elseif ($order->affiliate_status === 'Đã hủy')
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">Đã hủy</span>
                                        @else
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">{{ $order->affiliate_status }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right font-mono text-xs">{{ number_format((float) $order->net_commission, 0, ',', '.') }}</td>
                                    <td class="py-2 pr-4 text-right font-mono text-xs">{{ number_format((float) $order->cashback_amount, 0, ',', '.') }}</td>
                                    <td class="py-2 pr-4 text-xs">{{ $order->username }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
