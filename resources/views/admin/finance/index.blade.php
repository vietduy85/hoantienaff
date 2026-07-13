<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
            {{ __('Quản lý tài chính') }}
        </h2>
    </x-slot>

    <div class="py-6 px-4 max-w-5xl mx-auto">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

            {{-- Card 1: Tổng hoa hồng --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng hoa hồng</div>
                <div class="text-2xl font-bold text-gray-800">{{ number_format($totalCommission, 0, ',', '.') }}đ</div>
            </div>

            {{-- Card 2: Đã về ví khách --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Đã về ví khách</div>
                <div class="text-2xl font-bold text-emerald-600">{{ number_format($totalWallet, 0, ',', '.') }}đ</div>
            </div>

            {{-- Card 3: Còn lại --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Còn lại</div>
                <div class="text-2xl font-bold text-amber-600">{{ number_format($remaining, 0, ',', '.') }}đ</div>
            </div>

            {{-- Card 4: Tỷ lệ hoàn tiền --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tỷ lệ hoàn tiền</div>
                <div class="text-2xl font-bold text-gray-800">{{ number_format($cashbackRate, 2, ',', '.') }}%</div>
            </div>

            {{-- Card 5: Giới hạn --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Giới hạn</div>
                <div class="text-2xl font-bold text-gray-800">{{ number_format($safeLimit, 0, ',', '.') }}%</div>
            </div>

            {{-- Card 6: Trạng thái --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Trạng thái</div>
                <div class="mt-1">
                    @if($isSafe)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-emerald-100 text-emerald-700">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            An toàn
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-700">
                            <span class="w-2 h-2 rounded-full bg-red-500"></span>
                            Vượt giới hạn
                        </span>
                    @endif
                </div>
            </div>

            {{-- Card 7: Tổng số đơn Completed --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng số đơn Completed</div>
                <div class="text-2xl font-bold text-gray-800">{{ number_format($totalOrders, 0, ',', '.') }}</div>
            </div>

        </div>
    </div>
</x-app-layout>
