<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
            {{ __('Đồng bộ đơn hàng TikTok, Lazada, ShopeeFood & Shopee') }}
        </h2>
    </x-slot>

    <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">

        {{-- ShopeeFood Cookie Config --}}
        <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Cấu hình API ShopeeFood</h3>
                    <p class="text-sm text-gray-500 mt-1">Import cookie JSON từ trình duyệt để sử dụng cho đồng bộ ShopeeFood</p>
                </div>
                <div class="text-sm text-gray-600">
                    Trạng thái: <span class="font-medium {{ ($shopeeFoodCookieConfigured ?? false) ? 'text-green-600' : 'text-red-600' }}">{{ ($shopeeFoodCookieConfigured ?? false) ? 'Đã cấu hình' : 'Chưa cấu hình' }}</span>
                    @if(!empty($shopeeFoodCookieMeta['imported_at'] ?? null))
                        <span class="ml-2 text-gray-500">| Import gần nhất: {{ $shopeeFoodCookieMeta['imported_at'] }}</span>
                    @endif
                </div>
            </div>
            <div class="px-5 py-4 space-y-3">
                @if(session('shopeefood_cookie_success'))
                    <div class="bg-green-50 border border-green-200 text-green-700 rounded-xl p-3 text-sm">{{ session('shopeefood_cookie_success') }}</div>
                @endif
                @if(session('shopeefood_cookie_error'))
                    <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl p-3 text-sm">{{ session('shopeefood_cookie_error') }}</div>
                @endif
                <div class="flex flex-wrap items-center gap-3">
                    <form action="{{ route('admin.tiktok-order-sync.shopeefood-cookie.import') }}" method="POST" enctype="multipart/form-data" class="flex items-center gap-2">
                        @csrf
                        <input type="file" name="cookie_json" accept="application/json,.json,.txt" required class="text-sm text-gray-700">
                        <button type="submit" class="inline-flex items-center px-3 py-2 rounded-xl bg-blue-600 text-white text-sm font-medium hover:bg-blue-700">Import cookie JSON</button>
                    </form>
                    <form action="{{ route('admin.tiktok-order-sync.shopeefood-cookie.test') }}" method="POST">
                        @csrf
                        <button type="submit" class="inline-flex items-center px-3 py-2 rounded-xl bg-gray-100 text-gray-700 text-sm font-medium hover:bg-gray-200">Kiểm tra cookie</button>
                    </form>
                </div>
                <p class="text-xs text-gray-500">Hỗ trợ file JSON dạng mảng cookie (name/value) hoặc object. Cookie được mã hóa khi lưu, không hiển thị trên giao diện.</p>
            </div>
        </div>

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

        @if (session('lazada_sync_error'))
            <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-sm">
                {{ session('lazada_sync_error') }}
            </div>
        @endif

@if (session('lazada_sync_result'))
                @php $res = session('lazada_sync_result'); @endphp
                <div class="rounded-2xl p-4 text-sm {{ $res['success'] ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-amber-50 border border-amber-200 text-amber-800' }}">
                    <div class="font-semibold mb-2">{{ $res['message'] }}</div>
                    <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1">
                        <div class="flex justify-between"><dt class="text-gray-500">Tháng đã quét</dt><dd class="font-mono">{{ $res['months_fetched'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Số trang API</dt><dd class="font-mono">{{ $res['pages_fetched'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Tổng record</dt><dd class="font-mono">{{ $res['records_fetched'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Đơn mới (đã ghi)</dt><dd class="font-mono">{{ $res['inserted'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Đơn cập nhật (đã ghi)</dt><dd class="font-mono">{{ $res['updated'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Đang xử lý</dt><dd class="font-mono">{{ $res['pending'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Hoàn thành</dt><dd class="font-mono">{{ $res['completed'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Trả hàng/đã hủy</dt><dd class="font-mono">{{ $res['cancelled'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Chưa xác định user</dt><dd class="font-mono">{{ $res['unresolved_users'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Wallet credits</dt><dd class="font-mono">{{ $res['wallet_credits'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Wallet reversals</dt><dd class="font-mono">{{ $res['wallet_reversals'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Status ngoài mapping</dt><dd class="font-mono">{{ $res['unknown_statuses'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Line invalid</dt><dd class="font-mono">{{ $res['invalid_lines'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Errors</dt><dd class="font-mono">{{ $res['errors'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Commission (estPayout)</dt><dd class="font-mono">{{ number_format((float) $res['total_commission'], 0, ',', '.') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Cashback estimate</dt><dd class="font-mono">{{ number_format((float) $res['cashback_estimate'], 0, ',', '.') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Duration</dt><dd class="font-mono">{{ $res['duration'] }}s</dd></div>
                    </dl>
                </div>
            @endif

        @if (session('shopee_sync_error'))
            <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-sm">
                {{ session('shopee_sync_error') }}
            </div>
        @endif

        @if (session('shopee_sync_result'))
            @php $res = session('shopee_sync_result'); @endphp
            <div class="rounded-2xl p-4 text-sm {{ $res['success'] ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-amber-50 border border-amber-200 text-amber-800' }}">
                <div class="font-semibold mb-2">{{ $res['message'] }}</div>
                @if(!empty($res['notice']))
                    <div class="text-amber-700 mb-2">{{ $res['notice'] }}</div>
                @endif
                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1">
                    <div class="flex justify-between"><dt class="text-gray-500">API</dt><dd class="font-mono">{{ $res['api'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Trạng thái</dt><dd class="font-mono">{{ $res['success'] ? 'Thành công' : 'Có lỗi' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Trạng thái đồng bộ</dt><dd class="font-mono">{{ $res['sync_state'] ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">HTTP status</dt><dd class="font-mono">{{ $res['api_http_status'] ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Business status</dt><dd class="font-mono">{{ $res['api_business_status'] ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Trang API / record</dt><dd class="font-mono">{{ $res['api_pages'] ?? '-' }} / {{ $res['api_records'] ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Từ ngày</dt><dd class="font-mono">{{ $res['from'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Đến ngày</dt><dd class="font-mono">{{ $res['to'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Items đọc được</dt><dd class="font-mono">{{ $res['raw_items'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Đơn</dt><dd class="font-mono">{{ $res['orders'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Đơn mới (đã ghi)</dt><dd class="font-mono">{{ $res['inserted'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Đơn cập nhật (đã ghi)</dt><dd class="font-mono">{{ $res['updated'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Bỏ qua (protected)</dt><dd class="font-mono">{{ $res['protected'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">User conflict</dt><dd class="font-mono">{{ $res['user_conflict'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Sub ID mismatch</dt><dd class="font-mono">{{ $res['sub_id_mismatch'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Cashback mới (ví)</dt><dd class="font-mono">{{ $res['wallet_credits'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Lỗi ví</dt><dd class="font-mono">{{ $res['wallet_errors'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Duration</dt><dd class="font-mono">{{ $res['duration'] }}s</dd></div>
                </dl>
            </div>
        @endif

        {{-- Form --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-1">Đồng bộ từ RioHub, Lazada, ShopeeFood &amp; AddLiveTag API (Shopee)</h3>
            <p class="text-sm text-gray-500 mb-4">
                Một nút đồng bộ chạy lần lượt TikTok (RioHub), Lazada (conversion report),
                ShopeeFood (addlivetag) rồi Shopee (AddLiveTag API). Cả bốn đều ghi đơn &amp; ví ở chế độ
                <strong>REAL</strong> — đồng bộ lặp lại không nhân đôi cashback
                (idempotent). Kết quả từng nền tảng hiển thị riêng.
            </p>
            <div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 mb-2 text-sm text-gray-600">
                <div>
                    <span class="font-medium text-gray-700">Lần đồng bộ TikTok gần nhất:</span>
                    <span class="font-mono">{{ $lastTikTokSyncAt ? \Illuminate\Support\Carbon::parse($lastTikTokSyncAt)->format('d/m/Y H:i:s') : 'Chưa có' }}</span>
                </div>
                <div>
                    <span class="font-medium text-gray-700">Đơn cập nhật TikTok gần nhất:</span>
                    <span class="font-mono" data-testid="tiktok-order-update-time">{{ $orderStatuses['tiktok']['last_order_update_at'] ? $orderStatuses['tiktok']['last_order_update_at']->format('d/m/Y H:i:s') : 'Chưa có' }}</span>
                </div>
                @if ($orderStatuses['tiktok']['attempt'] !== null)
                    <div>
                        <span class="font-medium text-gray-700">Lần thử đồng bộ gần nhất:</span>
                        <span class="font-mono">
                            {{ $orderStatuses['tiktok']['attempt']['at'] ? $orderStatuses['tiktok']['attempt']['at']->format('d/m/Y H:i:s') : '—' }}
                            ({{ ['success' => 'Thành công', 'failed' => 'Thất bại', 'skipped' => 'Bị bỏ qua'][$orderStatuses['tiktok']['attempt']['state']] ?? $orderStatuses['tiktok']['attempt']['state'] }})
                        </span>
                    </div>
                @endif
            </div>
            <div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 mb-2 text-sm text-gray-600">
                <div>
                    <span class="font-medium text-gray-700">Lần đồng bộ ShopeeFood gần nhất:</span>
                    <span class="font-mono">{{ $lastShopeeFoodSyncAt ? \Illuminate\Support\Carbon::parse($lastShopeeFoodSyncAt)->format('d/m/Y H:i:s') : 'Chưa có' }}</span>
                </div>
            </div>
            <div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 mb-2 text-sm text-gray-600">
                <div>
                    <span class="font-medium text-gray-700">Lần đồng bộ Lazada gần nhất:</span>
                    <span class="font-mono">{{ $lastLazadaSyncAt ? \Illuminate\Support\Carbon::parse($lastLazadaSyncAt)->format('d/m/Y H:i:s') : 'Chưa có' }}</span>
                </div>
                <div>
                    <span class="font-medium text-gray-700">Đơn cập nhật Lazada gần nhất:</span>
                    <span class="font-mono" data-testid="lazada-order-update-time">{{ $orderStatuses['lazada']['last_order_update_at'] ? $orderStatuses['lazada']['last_order_update_at']->format('d/m/Y H:i:s') : 'Chưa có' }}</span>
                </div>
                @if ($orderStatuses['lazada']['attempt'] !== null)
                    <div>
                        <span class="font-medium text-gray-700">Lần thử đồng bộ gần nhất:</span>
                        <span class="font-mono">
                            {{ $orderStatuses['lazada']['attempt']['at'] ? $orderStatuses['lazada']['attempt']['at']->format('d/m/Y H:i:s') : '—' }}
                            ({{ ['success' => 'Thành công', 'failed' => 'Thất bại', 'skipped' => 'Bị bỏ qua'][$orderStatuses['lazada']['attempt']['state']] ?? $orderStatuses['lazada']['attempt']['state'] }})
                        </span>
                    </div>
                @endif
            </div>
            <div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 mb-1 text-sm text-gray-600">
                <span class="font-medium text-gray-700">Lần đồng bộ Shopee gần nhất:</span>
                <span class="font-mono">{{ $lastShopeeSyncAt ? \Illuminate\Support\Carbon::parse($lastShopeeSyncAt)->format('d/m/Y H:i:s') : 'Chưa có' }}</span>
            </div>
            <div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 mb-4 text-sm text-gray-600">
                <span class="font-medium text-gray-700">Lần cập nhật đơn Shopee từ LiveTag gần nhất:</span>
                <span class="font-mono">{{ $shopeeAlert['last_order_update_at'] ? $shopeeAlert['last_order_update_at']->format('d/m/Y H:i:s') : 'Chưa có' }}</span>
                @if($shopeeAlert['attempt'] !== null)
                    <span class="text-gray-400 mx-1">·</span>
                    <span class="font-medium text-gray-700">Lần thử đồng bộ gần nhất:</span>
                    <span class="font-mono">
                        {{ $shopeeAlert['attempt']['at'] ? $shopeeAlert['attempt']['at']->format('d/m/Y H:i:s') : '—' }}
                        ({{ ['success' => 'Thành công', 'failed' => 'Thất bại', 'skipped' => 'Bị bỏ qua'][$shopeeAlert['attempt']['state']] ?? $shopeeAlert['attempt']['state'] }})
                    </span>
                @endif
            </div>

            @if($shopeeAlert['should_warn'])
                <div data-testid="shopee-stale-warning"
                     class="bg-red-50 border border-red-300 rounded-2xl p-4 text-sm mb-4"
                     role="alert">
                    <p class="font-semibold text-red-700 mb-2">{{ $shopeeAlert['message'] }}</p>
                    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-1 text-red-600">
                        <div class="flex justify-between sm:justify-start sm:gap-2">
                            <dt class="font-medium">Lần cập nhật đơn gần nhất:</dt>
                            <dd class="font-mono">{{ $shopeeAlert['last_order_update_at'] ? $shopeeAlert['last_order_update_at']->format('d/m/Y H:i:s') : 'Chưa từng có' }}</dd>
                        </div>
                        <div class="flex justify-between sm:justify-start sm:gap-2">
                            <dt class="font-medium">Lần đồng bộ gần nhất:</dt>
                            <dd class="font-mono">{{ $shopeeAlert['last_sync_at'] ? $shopeeAlert['last_sync_at']->format('d/m/Y H:i:s') : 'Chưa có' }}</dd>
                        </div>
                        <div class="flex justify-between sm:justify-start sm:gap-2">
                            <dt class="font-medium">Lần thử gần nhất:</dt>
                            <dd class="font-mono">
                                @if($shopeeAlert['attempt'] !== null)
                                    {{ $shopeeAlert['attempt']['at'] ? $shopeeAlert['attempt']['at']->format('d/m/Y H:i:s') : '—' }}
                                    ({{ ['success' => 'Thành công', 'failed' => 'Thất bại', 'skipped' => 'Bị bỏ qua'][$shopeeAlert['attempt']['state']] ?? $shopeeAlert['attempt']['state'] }}{{ $shopeeAlert['attempt']['reason'] ? ' · '.$shopeeAlert['attempt']['reason'] : '' }})
                                @else
                                    Chưa ghi nhận
                                @endif
                            </dd>
                        </div>
                    </dl>
                    <p class="text-xs text-red-500 mt-2">
                        Thời gian hiển thị theo giờ Việt Nam (Asia/Ho_Chi_Minh).
                        {{ $shopeeAlert['hours_stale'] !== null ? 'Đã stale '.$shopeeAlert['hours_stale'].' giờ (ngưỡng '.$shopeeAlert['threshold_hours'].' giờ).' : 'Chưa có đơn Shopee nào được cập nhật từ LiveTag.' }}
                    </p>
                </div>
            @endif

            @include('admin.order-sync.partials._stale-warning', [
                'alert' => $orderStatuses['tiktok'],
                'testid' => 'tiktok-stale-warning',
                'emptyNote' => 'Chưa có đơn TikTok nào được cập nhật.',
            ])
            @include('admin.order-sync.partials._stale-warning', [
                'alert' => $orderStatuses['lazada'],
                'testid' => 'lazada-stale-warning',
                'emptyNote' => 'Chưa có đơn Lazada nào được cập nhật.',
            ])

            <form method="POST" action="{{ route('admin.tiktok-order-sync.sync') }}" class="space-y-4"
                  onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').textContent = 'Đang đồng bộ TikTok, Lazada, ShopeeFood & Shopee...';">
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
                    không được cấp cashback.<br>
                    Lazada: API chỉ trả dữ liệu <strong>theo từng tháng dương lịch</strong> và commission lấy từ payout
                    thực tế (<code class="font-mono">estPayout</code>), không tự tính. Line thiếu orderId/subOrderId/sku bị
                    INVALID; status nằm ngoài mapping tài liệu được giữ là "Đang xử lý" (không credit) và đếm riêng để
                    chốt mapping khi có dữ liệu thật.<br>
                    Shopee: đọc AddLiveTag API (cửa sổ 14 ngày), ghi đơn &amp; ví thật (idempotent — không nhân đôi
                    cashback khi bấm lại); pagination không đủ trang sẽ báo THẤT BẠI và không ghi gì.
                </div>

                <div>
                    <button type="submit"
                            class="inline-flex items-center justify-center px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-300">
                        Đồng bộ đơn hàng TikTok, Lazada, ShopeeFood &amp; Shopee
                    </button>
                </div>
            </form>
        </div>

        {{-- Backup database --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-800">Backup database</h3>
                <span class="text-xs text-gray-400">Chạy tự động 01:30 &amp; 13:30 hằng ngày</span>
            </div>

            @unless ($backupStatus['readable'])
                <div class="bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 text-sm text-gray-600">
                    <span class="font-medium text-gray-700">Trạng thái:</span>
                    <span class="font-mono text-gray-800">Không đọc được trạng thái backup hiện tại.</span>
                </div>
                <p class="text-xs text-gray-400 mt-2">
                    File báo cáo chưa được tạo hoặc chưa thể đọc. Kiểm tra script backup
                    (scripts/database-backup) sau lần chạy theo lịch tiếp theo.
                </p>
            @else
                <div class="flex items-center justify-between bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 mb-2 text-sm text-gray-600">
                    <span class="font-medium text-gray-700">Tóm tắt:</span>
                    <span class="text-gray-800">{{ $backupStatus['headline'] }}</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div class="border border-gray-100 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Backup local</span>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold
                                {{ $backupStatus['last_backup']['status'] === 'SUCCESS' ? 'bg-emerald-100 text-emerald-700' : '' }}
                                {{ $backupStatus['last_backup']['status'] === 'FAILED' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $backupStatus['last_backup']['status'] === 'PENDING' ? 'bg-amber-100 text-amber-700' : '' }}
                                {{ $backupStatus['last_backup']['status'] === 'UNKNOWN' ? 'bg-gray-100 text-gray-600' : '' }}">
                                {{ $backupStatus['last_backup']['label'] }}
                            </span>
                        </div>
                        @if ($backupStatus['last_backup']['status'] === 'UNKNOWN')
                            <p class="text-sm text-gray-500">Chưa có thông tin.</p>
                        @else
                            <dl class="text-sm space-y-1">
                                <div class="flex justify-between"><dt class="text-gray-500">Thời điểm</dt>
                                    <dd class="font-mono text-xs">{{ $backupStatus['last_backup']['time'] ? \Illuminate\Support\Carbon::parse($backupStatus['last_backup']['time'])->format('d/m/Y H:i:s') : '—' }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500">Set</dt>
                                    <dd class="font-mono text-xs break-all">{{ $backupStatus['last_backup']['set_id'] ?? '—' }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500">Số CSDL</dt>
                                    <dd class="font-mono text-xs">{{ $backupStatus['last_backup']['db_count'] }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500">Dung lượng</dt>
                                    <dd class="font-mono text-xs">{{ number_format($backupStatus['last_backup']['total_size'] / 1048576, 2, ',', '.') }} MB</dd></div>
                            </dl>
                            @if ($backupStatus['last_backup']['error'])
                                <p class="mt-2 text-xs text-red-600 break-words">{{ $backupStatus['last_backup']['error'] }}</p>
                            @endif
                        @endif
                    </div>

                    <div class="border border-gray-100 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">OneDrive</span>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold
                                {{ $backupStatus['onedrive']['status'] === 'SUCCESS' ? 'bg-emerald-100 text-emerald-700' : '' }}
                                {{ $backupStatus['onedrive']['status'] === 'FAILED' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $backupStatus['onedrive']['status'] === 'PENDING' ? 'bg-amber-100 text-amber-700' : '' }}
                                {{ $backupStatus['onedrive']['status'] === 'UNKNOWN' ? 'bg-gray-100 text-gray-600' : '' }}">
                                {{ $backupStatus['onedrive']['label'] }}
                            </span>
                        </div>
                        @if ($backupStatus['onedrive']['status'] === 'SUCCESS')
                            <dl class="text-sm space-y-1">
                                <div class="flex justify-between"><dt class="text-gray-500">File đã xác minh</dt>
                                    <dd class="font-mono text-xs">{{ $backupStatus['onedrive']['verified_count'] }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500">Thời điểm</dt>
                                    <dd class="font-mono text-xs">{{ $backupStatus['onedrive']['time'] ? \Illuminate\Support\Carbon::parse($backupStatus['onedrive']['time'])->format('d/m/Y H:i:s') : '—' }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500">Set</dt>
                                    <dd class="font-mono text-xs break-all">{{ $backupStatus['onedrive']['set_id'] ?? '—' }}</dd></div>
                            </dl>
                            <p class="mt-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5">
                                {{ $backupStatus['cloud_sync']['note'] }}
                            </p>
                        @elseif ($backupStatus['onedrive']['status'] === 'PENDING')
                            <p class="text-sm text-gray-500">Đang chờ sao chép sang OneDrive.</p>
                        @elseif ($backupStatus['onedrive']['status'] === 'FAILED')
                            <p class="text-sm text-gray-500">Lần thử trước gặp lỗi.</p>
                            @if ($backupStatus['onedrive']['error'])
                                <p class="mt-2 text-xs text-red-600 break-words">{{ $backupStatus['onedrive']['error'] }}</p>
                            @endif
                        @else
                            <p class="text-sm text-gray-500">Chưa có thông tin.</p>
                        @endif
                    </div>
                </div>

                <p class="text-xs text-gray-400 mt-3">
                    Thư mục đích chỉ vừa đặt tại khu vực đồng bộ OneDrive (cùng máy). Việc đã tải lên
                    cloud không thể xác minh tự động — trạng thái cloud luôn ghi nhận chưa xác minh.
                    Backup gốc trên máy không bị thay đổi.
                </p>
            @endunless
        </div>

        {{-- Daily orders by ordered date (Asia/Ho_Chi_Minh) --}}
        @php
            $todayLabel = isset($now) ? $now->format('d/m') : now()->format('d/m');
            $yesterdayLabel = isset($now) ? $now->copy()->subDay()->format('d/m') : now()->subDay()->format('d/m');
            $thisMonthLabel = isset($now) ? $now->format('m/Y') : now()->format('m/Y');
            $lastMonthLabel = isset($now) ? $now->copy()->subMonth()->format('m/Y') : now()->subMonth()->format('m/Y');
        @endphp
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">TikTok</div>
                <div class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between"><span>Đơn đặt hôm nay ({{ $todayLabel }})</span><span class="font-mono font-semibold">{{ number_format($dailyOrders['tiktok']['today'], 0, ',', '.') }} đơn</span></div>
                    <div class="flex justify-between"><span>Đơn đặt hôm qua ({{ $yesterdayLabel }})</span><span class="font-mono">{{ number_format($dailyOrders['tiktok']['yesterday'], 0, ',', '.') }} đơn</span></div>
                    @php $d = $dailyOrders['tiktok']['diff']; $p = $dailyOrders['tiktok']['pct']; @endphp
                    <div class="flex justify-between pt-1 border-t border-gray-50">
                        <span>Chênh lệch</span>
                        <span class="font-mono {{ $d === null ? 'text-gray-400' : ($d > 0 ? 'text-emerald-600' : ($d < 0 ? 'text-red-600' : 'text-gray-600')) }}">
                            @if($d === null) — @elseif($d >= 0)+{{ number_format($d, 0, ',', '.') }} đơn @else{{ number_format($d, 0, ',', '.') }} đơn @endif
                            @if($p !== null) ({{ $p >= 0 ? '+' : '' }}{{ $p }}%)@endif
                        </span>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">Lazada</div>
                <div class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between"><span>Đơn đặt hôm nay ({{ $todayLabel }})</span><span class="font-mono font-semibold">{{ number_format($dailyOrders['lazada']['today'], 0, ',', '.') }} đơn</span></div>
                    <div class="flex justify-between"><span>Đơn đặt hôm qua ({{ $yesterdayLabel }})</span><span class="font-mono">{{ number_format($dailyOrders['lazada']['yesterday'], 0, ',', '.') }} đơn</span></div>
                    @php $d = $dailyOrders['lazada']['diff']; $p = $dailyOrders['lazada']['pct']; @endphp
                    <div class="flex justify-between pt-1 border-t border-gray-50">
                        <span>Chênh lệch</span>
                        <span class="font-mono {{ $d === null ? 'text-gray-400' : ($d > 0 ? 'text-emerald-600' : ($d < 0 ? 'text-red-600' : 'text-gray-600')) }}">
                            @if($d === null) — @elseif($d >= 0)+{{ number_format($d, 0, ',', '.') }} đơn @else{{ number_format($d, 0, ',', '.') }} đơn @endif
                            @if($p !== null) ({{ $p >= 0 ? '+' : '' }}{{ $p }}%)@endif
                        </span>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">ShopeeFood</div>
                <div class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between"><span>Đơn đặt hôm nay ({{ $todayLabel }})</span><span class="font-mono font-semibold">{{ number_format($dailyOrders['shopeefood']['today'], 0, ',', '.') }} đơn</span></div>
                    <div class="flex justify-between"><span>Đơn đặt hôm qua ({{ $yesterdayLabel }})</span><span class="font-mono">{{ number_format($dailyOrders['shopeefood']['yesterday'], 0, ',', '.') }} đơn</span></div>
                    @php $d = $dailyOrders['shopeefood']['diff']; $p = $dailyOrders['shopeefood']['pct']; @endphp
                    <div class="flex justify-between pt-1 border-t border-gray-50">
                        <span>Chênh lệch</span>
                        <span class="font-mono {{ $d === null ? 'text-gray-400' : ($d > 0 ? 'text-emerald-600' : ($d < 0 ? 'text-red-600' : 'text-gray-600')) }}">
                            @if($d === null) — @elseif($d >= 0)+{{ number_format($d, 0, ',', '.') }} đơn @else{{ number_format($d, 0, ',', '.') }} đơn @endif
                            @if($p !== null) ({{ $p >= 0 ? '+' : '' }}{{ $p }}%)@endif
                        </span>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">Shopee</div>
                <div class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between"><span>Đơn đặt hôm nay ({{ $todayLabel }})</span><span class="font-mono font-semibold">{{ number_format($dailyOrders['shopee']['today'], 0, ',', '.') }} đơn</span></div>
                    <div class="flex justify-between"><span>Đơn đặt hôm qua ({{ $yesterdayLabel }})</span><span class="font-mono">{{ number_format($dailyOrders['shopee']['yesterday'], 0, ',', '.') }} đơn</span></div>
                    @php $d = $dailyOrders['shopee']['diff']; $p = $dailyOrders['shopee']['pct']; @endphp
                    <div class="flex justify-between pt-1 border-t border-gray-50">
                        <span>Chênh lệch</span>
                        <span class="font-mono {{ $d === null ? 'text-gray-400' : ($d > 0 ? 'text-emerald-600' : ($d < 0 ? 'text-red-600' : 'text-gray-600')) }}">
                            @if($d === null) — @elseif($d >= 0)+{{ number_format($d, 0, ',', '.') }} đơn @else{{ number_format($d, 0, ',', '.') }} đơn @endif
                            @if($p !== null) ({{ $p >= 0 ? '+' : '' }}{{ $p }}%)@endif
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Monthly orders by ordered date (Asia/Ho_Chi_Minh) --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">TikTok</div>
                <div class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between"><span>Tháng hiện tại ({{ $thisMonthLabel }})</span><span class="font-mono font-semibold">{{ number_format($monthlyOrders['tiktok']['this_month'], 0, ',', '.') }} đơn</span></div>
                    <div class="flex justify-between"><span>Tháng trước ({{ $lastMonthLabel }})</span><span class="font-mono">{{ number_format($monthlyOrders['tiktok']['last_month'], 0, ',', '.') }} đơn</span></div>
                    @php $d = $monthlyOrders['tiktok']['diff']; $p = $monthlyOrders['tiktok']['pct']; @endphp
                    <div class="flex justify-between pt-1 border-t border-gray-50">
                        <span>Chênh lệch</span>
                        <span class="font-mono {{ $d === null ? 'text-gray-400' : ($d > 0 ? 'text-emerald-600' : ($d < 0 ? 'text-red-600' : 'text-gray-600')) }}">
                            @if($d === null) — @elseif($d >= 0)+{{ number_format($d, 0, ',', '.') }} đơn @else{{ number_format($d, 0, ',', '.') }} đơn @endif
                            @if($p !== null) ({{ $p >= 0 ? '+' : '' }}{{ $p }}%)@endif
                        </span>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">Lazada</div>
                <div class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between"><span>Tháng hiện tại ({{ $thisMonthLabel }})</span><span class="font-mono font-semibold">{{ number_format($monthlyOrders['lazada']['this_month'], 0, ',', '.') }} đơn</span></div>
                    <div class="flex justify-between"><span>Tháng trước ({{ $lastMonthLabel }})</span><span class="font-mono">{{ number_format($monthlyOrders['lazada']['last_month'], 0, ',', '.') }} đơn</span></div>
                    @php $d = $monthlyOrders['lazada']['diff']; $p = $monthlyOrders['lazada']['pct']; @endphp
                    <div class="flex justify-between pt-1 border-t border-gray-50">
                        <span>Chênh lệch</span>
                        <span class="font-mono {{ $d === null ? 'text-gray-400' : ($d > 0 ? 'text-emerald-600' : ($d < 0 ? 'text-red-600' : 'text-gray-600')) }}">
                            @if($d === null) — @elseif($d >= 0)+{{ number_format($d, 0, ',', '.') }} đơn @else{{ number_format($d, 0, ',', '.') }} đơn @endif
                            @if($p !== null) ({{ $p >= 0 ? '+' : '' }}{{ $p }}%)@endif
                        </span>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">ShopeeFood</div>
                <div class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between"><span>Tháng hiện tại ({{ $thisMonthLabel }})</span><span class="font-mono font-semibold">{{ number_format($monthlyOrders['shopeefood']['this_month'], 0, ',', '.') }} đơn</span></div>
                    <div class="flex justify-between"><span>Tháng trước ({{ $lastMonthLabel }})</span><span class="font-mono">{{ number_format($monthlyOrders['shopeefood']['last_month'], 0, ',', '.') }} đơn</span></div>
                    @php $d = $monthlyOrders['shopeefood']['diff']; $p = $monthlyOrders['shopeefood']['pct']; @endphp
                    <div class="flex justify-between pt-1 border-t border-gray-50">
                        <span>Chênh lệch</span>
                        <span class="font-mono {{ $d === null ? 'text-gray-400' : ($d > 0 ? 'text-emerald-600' : ($d < 0 ? 'text-red-600' : 'text-gray-600')) }}">
                            @if($d === null) — @elseif($d >= 0)+{{ number_format($d, 0, ',', '.') }} đơn @else{{ number_format($d, 0, ',', '.') }} đơn @endif
                            @if($p !== null) ({{ $p >= 0 ? '+' : '' }}{{ $p }}%)@endif
                        </span>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">Shopee</div>
                <div class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between"><span>Tháng hiện tại ({{ $thisMonthLabel }})</span><span class="font-mono font-semibold">{{ number_format($monthlyOrders['shopee']['this_month'], 0, ',', '.') }} đơn</span></div>
                    <div class="flex justify-between"><span>Tháng trước ({{ $lastMonthLabel }})</span><span class="font-mono">{{ number_format($monthlyOrders['shopee']['last_month'], 0, ',', '.') }} đơn</span></div>
                    @php $d = $monthlyOrders['shopee']['diff']; $p = $monthlyOrders['shopee']['pct']; @endphp
                    <div class="flex justify-between pt-1 border-t border-gray-50">
                        <span>Chênh lệch</span>
                        <span class="font-mono {{ $d === null ? 'text-gray-400' : ($d > 0 ? 'text-emerald-600' : ($d < 0 ? 'text-red-600' : 'text-gray-600')) }}">
                            @if($d === null) — @elseif($d >= 0)+{{ number_format($d, 0, ',', '.') }} đơn @else{{ number_format($d, 0, ',', '.') }} đơn @endif
                            @if($p !== null) ({{ $p >= 0 ? '+' : '' }}{{ $p }}%)@endif
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- THỐNG KÊ HOA HỒNG THEO THÁNG --}}
        <div class="mt-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <h2 class="text-lg font-semibold text-gray-900">THỐNG KÊ HOA HỒNG THEO THÁNG</h2>
                <form method="GET" class="flex flex-wrap items-center gap-2 bg-white border border-gray-200 rounded-xl shadow-sm px-3 py-2">
                    <input type="hidden" name="platform" value="{{ request('platform') }}">
                    <input type="hidden" name="affiliate_status" value="{{ request('affiliate_status') }}">
                    <input type="hidden" name="sub_id1" value="{{ request('sub_id1') }}">
                    <span class="text-sm font-semibold text-gray-800">Kỳ thống kê</span>
                    <select name="month" class="text-sm border border-gray-300 rounded-lg px-2.5 py-1.5 bg-white font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        @for($m=1;$m<=12;$m++)
                            <option value="{{ $m }}" @selected(($summaryMonth ?? (int)now()->format('m')) == $m)>Tháng {{ $m }}</option>
                        @endfor
                    </select>
                    <select name="year" class="text-sm border border-gray-300 rounded-lg px-2.5 py-1.5 bg-white font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        @for($y=now()->format('Y');$y>=2023;$y--)
                            <option value="{{ $y }}" @selected(($summaryYear ?? (int)now()->format('Y')) == $y)>{{ $y }}</option>
                        @endfor
                    </select>
                    <button class="px-4 py-1.5 text-sm font-semibold bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">Xem thống kê</button>
                </form>
            </div>
            <p class="text-xs text-gray-500 mb-4">Tháng đang lọc theo ngày đặt hàng (ordered_at). Đếm đơn duy nhất theo platform + order_id.</p>
            @php
                $taxRate = 0.10;
                $platformsList = ['TikTok', 'Lazada', 'ShopeeFood', 'Shopee'];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach($platformsList as $pl)
                    @php
                        $p = $monthlyByPlatform[$pl] ?? null;
                        $g = (float)($p->total_commission ?? 0);
                        $c = (float)($p->total_cashback ?? 0);
                        $taxEst = $g * $taxRate;
                        $leftEst = $g - $taxEst - $c;
                    @endphp
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">{{ $pl }}</div>
                        <div class="text-sm text-gray-700 space-y-1.5">
                            <div class="flex justify-between"><span>Số đơn duy nhất</span><span class="font-mono font-semibold">{{ number_format($p->total_orders ?? 0, 0, ',', '.') }}</span></div>
                            <div class="flex justify-between"><span>Tổng giá trị đơn hàng</span><span class="font-mono">{{ number_format($p->total_order_amount ?? 0, 0, ',', '.') }} đ</span></div>
                            <div class="flex justify-between"><span>Tổng hoa hồng gộp</span><span class="font-mono font-semibold">{{ number_format($g, 0, ',', '.') }} đ</span></div>
                            <div class="flex justify-between"><span>Cashback đã thực sự ghi nhận/trả</span><span class="font-mono">{{ number_format($c, 0, ',', '.') }} đ</span></div>
                            <div class="flex justify-between"><span>Thuế ước tính (10%)</span><span class="font-mono">{{ number_format($taxEst, 0, ',', '.') }} đ</span></div>
                            <div class="pt-1.5 border-t border-gray-50">
                                <div class="flex justify-between"><span>Hoa hồng còn lại sau thuế và cashback</span><span class="font-mono font-semibold">{{ number_format($leftEst, 0, ',', '.') }} đ</span></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-sm font-semibold text-gray-900 mb-3">TỔNG HOA HỒNG TẤT CẢ SÀN TRONG THÁNG</div>
                @php
                    $ms = $monthlySummary ?? null;
                    $gt = (float)($ms->total_commission ?? 0);
                    $gc = (float)($ms->total_cashback ?? 0);
                    $taxEstTotal = $gt * $taxRate;
                    $gleftTotal = $gt - $taxEstTotal - $gc;
                @endphp
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 text-sm text-gray-700">
                    <div class="border border-gray-100 rounded-xl p-3">
                        <div class="text-xs text-gray-400">Số đơn duy nhất</div>
                        <div class="text-lg font-semibold font-mono">{{ number_format($ms->total_orders ?? 0, 0, ',', '.') }}</div>
                    </div>
                    <div class="border border-gray-100 rounded-xl p-3">
                        <div class="text-xs text-gray-400">Tổng hoa hồng gộp của cả 4 sàn</div>
                        <div class="text-lg font-semibold font-mono">{{ number_format($gt, 0, ',', '.') }} đ</div>
                    </div>
                    <div class="border border-gray-100 rounded-xl p-3">
                        <div class="text-xs text-gray-400">Tổng cashback đã trả khách</div>
                        <div class="text-lg font-semibold font-mono">{{ number_format($gc, 0, ',', '.') }} đ</div>
                    </div>
                    <div class="border border-gray-100 rounded-xl p-3">
                        <div class="text-xs text-gray-400">Thuế ước tính (10%)</div>
                        <div class="text-lg font-semibold font-mono">{{ number_format($taxEstTotal, 0, ',', '.') }} đ</div>
                    </div>
                    <div class="border border-gray-100 rounded-xl p-3">
                        <div class="text-xs text-gray-400">Hoa hồng còn lại sau thuế và cashback</div>
                        <div class="text-lg font-semibold font-mono text-gray-800">{{ number_format($gleftTotal, 0, ',', '.') }} đ</div>
                    </div>
                </div>
            </div>
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
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng Lazada</div>
                <div class="text-2xl font-bold text-gray-800">{{ number_format($stats['lazada']['total'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Lazada hoàn thành</div>
                <div class="text-2xl font-bold text-emerald-600">{{ number_format($stats['lazada']['settled'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Lazada hủy/hoàn</div>
                <div class="text-2xl font-bold text-red-600">{{ number_format($stats['lazada']['refunded'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng Shopee</div>
                <div class="text-2xl font-bold text-gray-800">{{ number_format($stats['shopee']['total'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Shopee hoàn thành</div>
                <div class="text-2xl font-bold text-emerald-600">{{ number_format($stats['shopee']['settled'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Shopee hủy/hoàn</div>
                <div class="text-2xl font-bold text-red-600">{{ number_format($stats['shopee']['refunded'], 0, ',', '.') }}</div>
            </div>
        </div>

        {{-- Recent orders --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-3">Đơn gần đây</h3>

            @if ($recentOrders->isEmpty())
                <p class="text-sm text-gray-500">Chưa có đơn TikTok / Lazada / ShopeeFood / Shopee nào được đồng bộ.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm min-w-[1100px]">
                        <colgroup>
                            <col style="width: 12%;">
                            <col style="width: 7%;">
                            <col style="width: 12%;">
                            <col style="width: 22%;">
                            <col style="width: 10%;">
                            <col style="width: 10%;">
                            <col style="width: 10%;">
                            <col style="width: 10%;">
                            <col style="width: 9%;">
                            <col style="width: 8%;">
                        </colgroup>
                        <thead class="text-left text-xs text-gray-400 uppercase tracking-wide">
                            <tr>
                                <th class="py-2 pr-4 font-normal">Order ID</th>
                                <th class="py-2 pr-4 font-normal">Nền tảng</th>
                                <th class="py-2 pr-4 font-normal">Ngày đặt hàng</th>
                                <th class="py-2 pr-4 font-normal">Sản phẩm</th>
                                <th class="py-2 pr-4 text-right font-normal">Hoa hồng sàn (%)</th>
                                <th class="py-2 pr-4 text-right font-normal">Hoa hồng người bán (%)</th>
                                <th class="py-2 pr-4 text-right font-normal">Tổng hoa hồng (%)</th>
                                <th class="py-2 pr-4 font-normal">Trạng thái</th>
                                <th class="py-2 pr-4 text-right font-normal">Cashback</th>
                                <th class="py-2 pr-4 font-normal">Người dùng</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-700">
                            @foreach ($recentOrders as $order)
                                @php
                                    $shopeeRate = $order->shopee_commission_rate ?? null;
                                    $sellerRate = $order->seller_commission_rate ?? null;
                                    if($shopeeRate !== null && $shopeeRate !== ''){ $shopeeRate = (float)$shopeeRate; if($shopeeRate > 1) $shopeeRate = $shopeeRate/100; }
                                    else { $shopeeRate = null; }
                                    if($sellerRate !== null && $sellerRate !== ''){ $sellerRate = (float)$sellerRate; if($sellerRate > 1) $sellerRate = $sellerRate/100; }
                                    else { $sellerRate = null; }
                                    $totalRate = null;
                                    if($shopeeRate !== null && $sellerRate !== null){ $totalRate = $shopeeRate + $sellerRate; }
                                    elseif($shopeeRate !== null && in_array($order->platform, ['TikTok'])){ $totalRate = $shopeeRate; }
                                    elseif($sellerRate !== null && in_array($order->platform, ['TikTok'])){ $totalRate = $sellerRate; }
                                    $highlight7 = false;
                                    if($order->platform === 'Shopee' && $shopeeRate !== null && abs($shopeeRate - 0.07) < 0.0001){ $highlight7 = true; }
                                    $itemName = $order->item_name ?? '';
                                    $words = preg_split('/\s+/', trim($itemName));
                                    if(is_array($words) && count($words) > 5){
                                        $short = implode(' ', array_slice($words, 0, 5)) . '...';
                                    }else{
                                        $short = $itemName;
                                    }
                                @endphp
                                <tr class="border-t border-gray-100 {{ $highlight7 ? 'bg-amber-50/40' : '' }}">
                                    <td class="py-2 pr-4 align-top font-mono text-xs break-words">{{ $order->order_id }}</td>
                                    <td class="py-2 pr-4 align-top text-xs break-words">
                                        {{ $order->platform }}
                                        @if($highlight7)<span class="ml-1 inline-flex px-1.5 py-0.5 rounded text-[10px] bg-amber-100 text-amber-700">Hoa hồng sàn 7%</span>@endif
                                    </td>
                                    <td class="py-2 pr-4 align-top text-xs font-mono break-words">
                                        @if($order->ordered_at)
                                            {{ \Illuminate\Support\Carbon::parse($order->ordered_at)->timezone(config('app.timezone', 'Asia/Ho_Chi_Minh'))->format('d/m/Y H:i:s') }}
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 align-top text-xs" style="white-space: normal; overflow-wrap: anywhere; word-break: break-word;" title="{{ $itemName }}">{{ $short }}</td>
                                    <td class="py-2 pr-4 align-top text-right font-mono text-xs break-words">@if($shopeeRate !== null){{ number_format($shopeeRate * 100, 2, ',', '.') }}% @else<span class="text-gray-400">—</span>@endif</td>
                                    <td class="py-2 pr-4 align-top text-right font-mono text-xs break-words">@if($sellerRate !== null){{ number_format($sellerRate * 100, 2, ',', '.') }}% @else<span class="text-gray-400">—</span>@endif</td>
                                    <td class="py-2 pr-4 align-top text-right font-mono text-xs break-words">@if($totalRate !== null){{ number_format($totalRate * 100, 2, ',', '.') }}% @else<span class="text-gray-400">—</span>@endif</td>
                                    <td class="py-2 pr-4 align-top">
                                        @if ($order->affiliate_status === 'Hoàn thành')
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">Hoàn thành</span>
                                        @elseif ($order->affiliate_status === 'Đã hủy')
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">Đã hủy</span>
                                        @else
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">{{ $order->affiliate_status }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 align-top text-right font-mono text-xs break-words">
                                        @if ((float) $order->cashback_amount > 0 && $order->affiliate_status === 'Hoàn thành')
                                            <span class="text-emerald-700 font-semibold">{{ number_format((float) $order->cashback_amount, 0, ',', '.') }} đ</span>
                                        @elseif ((float) $order->cashback_amount > 0)
                                            <span class="text-amber-600">
                                                {{ number_format((float) $order->cashback_amount, 0, ',', '.') }} đ
                                            </span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
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
