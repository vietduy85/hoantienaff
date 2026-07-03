<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('dashboard') }}" class="shrink-0">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
            </a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Tra cứu đơn hàng') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-lg mx-auto space-y-4">
        {{-- Search --}}
        <form method="GET" action="{{ route('orders.index') }}" class="space-y-3">
            <div class="relative">
                <input type="search" name="search" value="{{ $search }}"
                       placeholder="Tìm mã đơn hàng..."
                       class="w-full h-12 pl-4 pr-10 rounded-xl border border-gray-200 bg-white text-sm focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 transition-shadow shadow-sm">
                <button type="submit" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                </button>
            </div>

            {{-- Filter chips --}}
            <div class="flex gap-2 overflow-x-auto pb-1 scrollbar-hide">
                <a href="{{ route('orders.index', array_filter(['search' => $search, 'status' => null, 'platform' => null])) }}"
                   class="shrink-0 h-9 px-4 rounded-full text-sm font-medium flex items-center whitespace-nowrap transition-colors {{ !$status && !$platform ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-100 text-gray-600' }}">
                    Tất cả
                </a>

                @foreach ($platforms as $p)
                    <a href="{{ route('orders.index', array_filter(['search' => $search, 'status' => $status, 'platform' => $p == $platform ? null : $p])) }}"
                       class="shrink-0 h-9 px-4 rounded-full text-sm font-medium flex items-center whitespace-nowrap transition-colors {{ $p == $platform ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-100 text-gray-600' }}">
                        {{ $p }}
                    </a>
                @endforeach

                @foreach ($statuses as $s)
                    <a href="{{ route('orders.index', array_filter(['search' => $search, 'platform' => $platform, 'status' => $s == $status ? null : $s])) }}"
                       class="shrink-0 h-9 px-4 rounded-full text-sm font-medium flex items-center whitespace-nowrap transition-colors {{ $s == $status ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-100 text-gray-600' }}">
                        {{ $s }}
                    </a>
                @endforeach
            </div>
        </form>

        {{-- Order cards --}}
        @forelse ($orders as $order)
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
                $sc = $statusColors[$order->affiliate_status] ?? 'bg-gray-50 text-gray-600 border-gray-200';
                $sd = $statusDots[$order->affiliate_status] ?? '⚪';
            @endphp

            <a href="{{ route('orders.show', $order->order_id) }}" class="block">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-3 active:bg-gray-50 transition-colors">
                    {{-- Order ID + Status --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="shrink-0">📦</span>
                            <span class="font-mono text-sm font-semibold text-gray-800 truncate">{{ $order->order_id }}</span>
                        </div>
                        <span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border {{ $sc }}">
                            {{ $sd }} {{ $order->affiliate_status }}
                        </span>
                    </div>

                    {{-- Cashback --}}
                    @if ($order->total_cashback > 0)
                        <div class="text-emerald-600 font-bold text-xl sm:text-2xl">
                            💵 +{{ number_format($order->total_cashback, 0, ',', '.') }}đ
                        </div>
                    @endif

                    {{-- Shop --}}
                    <div class="flex items-center gap-2 text-sm text-gray-500">
                        <span>🏪</span>
                        <span class="truncate">{{ $order->shop_name ?? '—' }}</span>
                    </div>

                    {{-- Items + Amount + Date --}}
                    <div class="flex items-center justify-between text-sm">
                        <div class="flex items-center gap-3 text-gray-400">
                            <span>📦 {{ $order->item_count }} sản phẩm</span>
                            <span>💰 {{ number_format($order->order_amount, 0, ',', '.') }}đ</span>
                        </div>
                        <span class="text-gray-300 text-xs">
                            {{ $order->ordered_at ? \Carbon\Carbon::parse($order->ordered_at)->format('d/m/Y') : '—' }}
                        </span>
                    </div>

                    {{-- Arrow --}}
                    <div class="flex justify-end">
                        <span class="text-gray-300 text-sm">Xem chi tiết →</span>
                    </div>
                </div>
            </a>
        @empty
            <div class="bg-white rounded-2xl border border-dashed border-gray-200 p-8 text-center space-y-2">
                <span class="text-3xl block">📋</span>
                <p class="text-sm text-gray-400">Chưa có đơn hàng nào</p>
                @if ($search || $status || $platform)
                    <a href="{{ route('orders.index') }}" class="text-sm text-emerald-600 hover:underline">Xóa bộ lọc</a>
                @endif
            </div>
        @endforelse

        {{-- Pagination --}}
        @if ($orders->hasPages())
            <div class="pt-2">
                {{ $orders->appends(request()->query())->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
