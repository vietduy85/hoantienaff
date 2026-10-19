@if ($alert['should_warn'])
    <div data-testid="{{ $testid }}"
         class="bg-red-50 border border-red-300 rounded-2xl p-4 text-sm mb-4"
         role="alert">
        <p class="font-semibold text-red-700 mb-2">{{ $alert['message'] }}</p>
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-1 text-red-600">
            <div class="flex justify-between sm:justify-start sm:gap-2">
                <dt class="font-medium">Lần cập nhật đơn gần nhất:</dt>
                <dd class="font-mono">{{ $alert['last_order_update_at'] ? $alert['last_order_update_at']->format('d/m/Y H:i:s') : 'Chưa từng có' }}</dd>
            </div>
            <div class="flex justify-between sm:justify-start sm:gap-2">
                <dt class="font-medium">Lần đồng bộ gần nhất:</dt>
                <dd class="font-mono">{{ $alert['last_sync_at'] ? $alert['last_sync_at']->format('d/m/Y H:i:s') : 'Chưa có' }}</dd>
            </div>
            <div class="flex justify-between sm:justify-start sm:gap-2">
                <dt class="font-medium">Lần thử gần nhất:</dt>
                <dd class="font-mono">
                    @if ($alert['attempt'] !== null)
                        {{ $alert['attempt']['at'] ? $alert['attempt']['at']->format('d/m/Y H:i:s') : '—' }}
                        ({{ ['success' => 'Thành công', 'failed' => 'Thất bại', 'skipped' => 'Bị bỏ qua'][$alert['attempt']['state']] ?? $alert['attempt']['state'] }}{{ $alert['attempt']['reason'] ? ' · '.$alert['attempt']['reason'] : '' }})
                    @else
                        Chưa ghi nhận
                    @endif
                </dd>
            </div>
        </dl>
        <p class="text-xs text-red-500 mt-2">
            Thời gian hiển thị theo giờ Việt Nam (Asia/Ho_Chi_Minh).
            {{ $alert['hours_stale'] !== null ? 'Đã stale '.$alert['hours_stale'].' giờ (ngưỡng '.$alert['threshold_hours'].' giờ).' : $emptyNote }}
        </p>
    </div>
@endif
