<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('orders.index', request()->only(['search', 'status', 'platform'])) }}" class="shrink-0">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
            </a>
            <div class="min-w-0">
                <h2 class="font-bold text-sm text-gray-800 truncate">
                    {{ $summary->order_id }}
                </h2>
            </div>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-lg mx-auto space-y-4">
        {{-- Order summary card --}}
        @php
            $statusColors = [
                'Đang chờ xử lý' => 'bg-yellow-50 text-yellow-700 border-yellow-200',
                'Hoàn thành' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                'Đã thanh toán' => 'bg-blue-50 text-blue-700 border-blue-200',
                'Đã hủy' => 'bg-red-50 text-red-700 border-red-200',
            ];
            $statusDots = [
                'Đang chờ xử lý' => '🟡',
                'Hoàn thành' => '🟢',
                'Đã thanh toán' => '🔵',
                'Đã hủy' => '🔴',
            ];
            $sc = $statusColors[$summary->affiliate_status] ?? 'bg-gray-50 text-gray-600 border-gray-200';
            $sd = $statusDots[$summary->affiliate_status] ?? '⚪';

            $rateColors = [
                50 => 'bg-amber-50 text-amber-700 border-amber-200',
                60 => 'bg-blue-50 text-blue-700 border-blue-200',
                70 => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            ];
        @endphp

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-2">
            <div class="flex items-center justify-between">
                <span class="font-mono text-sm text-gray-800">📦 {{ $summary->order_id }}</span>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border {{ $sc }}">
                    {{ $sd }} {{ $summary->affiliate_status }}
                </span>
            </div>
            <div class="flex items-center gap-2 text-sm text-gray-500">
                <span>🏪</span>
                <span>{{ $summary->shop_name ?? '—' }}</span>
                <span class="ml-auto text-gray-300">{{ $summary->ordered_at ? \Carbon\Carbon::parse($summary->ordered_at)->format('d/m/Y') : '—' }}</span>
            </div>
            <div class="flex items-center gap-3 text-sm text-gray-400">
                <span>📦 {{ $summary->item_count }} sản phẩm</span>
                <span>💰 {{ number_format($summary->order_amount, 0, ',', '.') }}đ</span>
                <span>💵 +{{ number_format($summary->total_cashback, 0, ',', '.') }}đ</span>
            </div>
        </div>

        {{-- Item cards --}}
        @foreach ($items as $item)
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-3">
                {{-- Item name + platform --}}
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800 line-clamp-2">{{ $item->item_name ?? '—' }}</p>
                        @if ($item->model_id)
                            <p class="text-xs text-gray-400 mt-0.5">Model: {{ $item->model_id }}</p>
                        @endif
                    </div>
                    <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-50 text-orange-700 border border-orange-200">
                        {{ $item->platform ?? 'Shopee' }}
                    </span>
                </div>

                {{-- Divider --}}
                <hr class="border-gray-100">

                {{-- Price --}}
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">Giá</span>
                    <span class="text-sm font-semibold text-blue-600">{{ number_format($item->item_price * $item->quantity, 0, ',', '.') }}đ</span>
                </div>

                {{-- Commission --}}
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">Hoa hồng</span>
                    <span class="text-sm font-semibold text-purple-600">{{ number_format((int) floor($item->total_product_commission * 0.90), 0, ',', '.') }}đ</span>
                </div>

                {{-- Cashback rate --}}
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">Cashback Rate</span>
                    @php
                        $rc = $rateColors[$item->cashback_rate] ?? 'bg-gray-50 text-gray-600 border-gray-200';
                    @endphp
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $rc }}">
                        {{ $item->cashback_rate }}%
                    </span>
                </div>

                {{-- Cashback --}}
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">Cashback</span>
                    <span class="text-lg font-bold text-emerald-600">💵 +{{ number_format($item->cashback_amount, 0, ',', '.') }}đ</span>
                </div>

                {{-- Status --}}
                <div class="flex items-center justify-between pt-1">
                    <span class="text-sm text-gray-500">Trạng thái</span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border {{ $sc }}">
                        {{ $sd }} {{ $item->affiliate_status }}
                    </span>
                </div>
            </div>
        @endforeach

        {{-- Back link --}}
        <div class="text-center pt-2">
            <a href="{{ route('orders.index', request()->only(['search', 'status', 'platform'])) }}" class="text-sm text-emerald-600 hover:underline">
                ← Quay lại danh sách
            </a>
        </div>
    </div>
</x-app-layout>
