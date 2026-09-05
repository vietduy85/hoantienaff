<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
            {{ __('Đồng bộ đơn hàng TikTok & ShopeeFood') }}
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

        @if (session('shopeefood_sync_error'))
            <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-sm">
                {{ session('shopeefood_sync_error') }}
            </div>
        @endif

@if (session('shopeefood_sync_result'))
                @php $res = session('shopeefood_sync_result'); @endphp
                <div class="rounded-2xl p-4 text-sm {{ $res['success'] ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-amber-50 border border-amber-200 text-amber-800' }}">
                    <div class="font-semibold mb-2">{{ $res['message'] }}</div>
                    <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1">
                        <div class="flex justify-between"><dt class="text-gray-500">Tổng checkout</dt><dd class="font-mono">{{ $res['checkouts_fetched'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Tổng order</dt><dd class="font-mono">{{ $res['orders_fetched'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Tổng item</dt><dd class="font-mono">{{ $res['items_fetched'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Đơn mới (đã ghi)</dt><dd class="font-mono">{{ $res['inserted'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Đơn cập nhật (đã ghi)</dt><dd class="font-mono">{{ $res['updated'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Đang xử lý</dt><dd class="font-mono">{{ $res['pending'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Hoàn thành</dt><dd class="font-mono">{{ $res['completed'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Đã hủy</dt><dd class="font-mono">{{ $res['cancelled'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Chưa xác định user</dt><dd class="font-mono">{{ $res['unresolved_users'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Wallet credits</dt><dd class="font-mono">{{ $res['wallet_credits'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Wallet reversals</dt><dd class="font-mono">{{ $res['wallet_reversals'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Line invalid</dt><dd class="font-mono">{{ $res['invalid_lines'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Errors</dt><dd class="font-mono">{{ $res['errors'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Commission</dt><dd class="font-mono">{{ number_format((float) $res['total_commission'], 0, ',', '.') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Cashback estimate</dt><dd class="font-mono">{{ number_format((float) $res['cashback_estimate'], 0, ',', '.') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Duration</dt><dd class="font-mono">{{ $res['duration'] }}s</dd></div>
                    </dl>
                </div>
            @endif

        {{-- Form --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-1">Đồng bộ từ RioHub & ShopeeFood</h3>
            <p class="text-sm text-gray-500 mb-4">
                Một nút đồng bộ chạy lần lượt TikTok (RioHub) rồi ShopeeFood (addlivetag). TikTok ghi đơn & ví;
                ShopeeFood ghi đơn & ví ở chế độ <strong>REAL</strong> — đồng bộ lặp lại không nhân đôi cashback
                (idempotent). Kết quả từng nền tảng hiển thị riêng.
            </p>
            <div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 mb-2 text-sm text-gray-600">
                <span class="font-medium text-gray-700">Lần đồng bộ TikTok gần nhất:</span>
                <span class="font-mono">{{ $lastTikTokSyncAt ? \Illuminate\Support\Carbon::parse($lastTikTokSyncAt)->format('d/m/Y H:i:s') : 'Chưa có' }}</span>
            </div>
            <div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 mb-4 text-sm text-gray-600">
                <span class="font-medium text-gray-700">Lần đồng bộ ShopeeFood gần nhất:</span>
                <span class="font-mono">{{ $lastShopeeFoodSyncAt ? \Illuminate\Support\Carbon::parse($lastShopeeFoodSyncAt)->format('d/m/Y H:i:s') : 'Chưa có' }}</span>
            </div>

            <form method="POST" action="{{ route('admin.tiktok-order-sync.sync') }}" class="space-y-4"
                  onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').textContent = 'Đang đồng bộ TikTok & ShopeeFood...';">
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
                    TikTok: đơn SETTLED tính hoa hồng vào ví đã phân bổ, đơn chưa xác định chủ gán về
                    <code class="font-mono">tintuctonghop103</code>.<br>
                    ShopeeFood: ghi đơn & ví thật (idempotent — không nhân đôi cashback khi bấm lại); line thiếu
                    <code class="font-mono">promotion_id</code> bị đánh dấu INVALID và không ghi; user không xác định sẽ
                    không được cấp cashback.
                </div>

                <div>
                    <button type="submit"
                            class="inline-flex items-center justify-center px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-300">
                        Đồng bộ đơn hàng TikTok & ShopeeFood
                    </button>
                </div>
            </form>
        </div>

        {{-- Stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng TikTok</div>
                <div class="text-2xl font-bold text-gray-800">{{ number_format($stats['tiktok']['total'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">TikTok hoàn thành</div>
                <div class="text-2xl font-bold text-emerald-600">{{ number_format($stats['tiktok']['settled'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">TikTok hủy/hoàn</div>
                <div class="text-2xl font-bold text-red-600">{{ number_format($stats['tiktok']['refunded'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng ShopeeFood</div>
                <div class="text-2xl font-bold text-gray-800">{{ number_format($stats['shopeefood']['total'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">ShopeeFood hoàn thành</div>
                <div class="text-2xl font-bold text-emerald-600">{{ number_format($stats['shopeefood']['settled'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">ShopeeFood hủy/hoàn</div>
                <div class="text-2xl font-bold text-red-600">{{ number_format($stats['shopeefood']['refunded'], 0, ',', '.') }}</div>
            </div>
        </div>

        {{-- Recent orders --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-3">Đơn gần đây</h3>

            @if ($recentOrders->isEmpty())
                <p class="text-sm text-gray-500">Chưa có đơn TikTok / ShopeeFood nào được đồng bộ.</p>
            @else
                <div>
                    <table class="w-full text-sm table-fixed">
                        <colgroup>
                            <col style="width: 16%;">
                            <col style="width: 9%;">
                            <col style="width: 30%;">
                            <col style="width: 15%;">
                            <col style="width: 10%;">
                            <col style="width: 10%;">
                            <col style="width: 10%;">
                        </colgroup>
                        <thead class="text-left text-xs text-gray-400 uppercase tracking-wide">
                            <tr>
                                <th class="py-2 pr-4 font-normal">Order ID</th>
                                <th class="py-2 pr-4 font-normal">Nền tảng</th>
                                <th class="py-2 pr-4 font-normal">Sản phẩm</th>
                                <th class="py-2 pr-4 font-normal">Trạng thái</th>
                                <th class="py-2 pr-4 text-right font-normal">Hoa hồng (net)</th>
                                <th class="py-2 pr-4 text-right font-normal">Cashback</th>
                                <th class="py-2 pr-4 font-normal">Người dùng</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-700">
                            @foreach ($recentOrders as $order)
                                <tr class="border-t border-gray-100">
                                    <td class="py-2 pr-4 align-top font-mono text-xs break-words">{{ $order->order_id }}</td>
                                    <td class="py-2 pr-4 align-top text-xs break-words">{{ $order->platform }}</td>
                                    <td class="py-2 pr-4 align-top text-xs" style="white-space: normal; overflow-wrap: anywhere; word-break: break-word;">{{ $order->item_name }}</td>
                                    <td class="py-2 pr-4 align-top">
                                        @if ($order->affiliate_status === 'Hoàn thành')
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">Hoàn thành</span>
                                        @elseif ($order->affiliate_status === 'Đã hủy')
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">Đã hủy</span>
                                        @else
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">{{ $order->affiliate_status }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 align-top text-right font-mono text-xs break-words">{{ number_format((float) $order->net_commission, 0, ',', '.') }}</td>
                                    <td class="py-2 pr-4 align-top text-right font-mono text-xs break-words">{{ number_format((float) $order->cashback_amount, 0, ',', '.') }}</td>
                                    <td class="py-2 pr-4 align-top text-xs break-words">{{ $order->username }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
