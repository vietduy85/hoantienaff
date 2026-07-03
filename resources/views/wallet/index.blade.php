<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('dashboard') }}" class="shrink-0">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
            </a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Ví tiền') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-lg mx-auto space-y-4">

        {{-- Card 1: Số dư khả dụng --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-500">💰 Số dư khả dụng</span>
            </div>
            <div class="text-emerald-600 font-bold text-3xl sm:text-4xl tracking-tight">
                {{ number_format($available, 0, ',', '.') }}<span class="text-lg sm:text-xl">đ</span>
            </div>
            <button class="w-full h-12 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-semibold text-base rounded-xl transition-colors shadow-sm">
                Rút tiền
            </button>
        </div>

        {{-- Card 2: Tiền chờ xác nhận --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
            <div class="flex items-center gap-2">
                <span class="text-lg">🕒</span>
                <span class="text-sm font-medium text-gray-500">Tiền chờ xác nhận</span>
            </div>
            <div class="text-amber-500 font-bold text-2xl sm:text-3xl tracking-tight">
                {{ number_format($pending, 0, ',', '.') }}<span class="text-base sm:text-lg">đ</span>
            </div>
        </div>

        {{-- Card 3: Đã thanh toán --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
            <div class="flex items-center gap-2">
                <span class="text-lg">💸</span>
                <span class="text-sm font-medium text-gray-500">Đã thanh toán</span>
            </div>
            <div class="text-blue-500 font-bold text-2xl sm:text-3xl tracking-tight">
                {{ number_format($paid, 0, ',', '.') }}<span class="text-base sm:text-lg">đ</span>
            </div>
        </div>

        {{-- Card 4: Lịch sử giao dịch --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
            <div class="flex items-center gap-2">
                <span class="text-lg">📋</span>
                <span class="text-sm font-medium text-gray-500">Lịch sử giao dịch</span>
            </div>

            {{-- Table header --}}
            <div class="overflow-x-auto -mx-5">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100">
                            <th class="px-5 py-3 text-left text-gray-400 font-medium text-xs uppercase tracking-wider">Ngày</th>
                            <th class="px-5 py-3 text-left text-gray-400 font-medium text-xs uppercase tracking-wider">Loại</th>
                            <th class="px-5 py-3 text-right text-gray-400 font-medium text-xs uppercase tracking-wider">Số tiền</th>
                            <th class="px-5 py-3 text-center text-gray-400 font-medium text-xs uppercase tracking-wider">Trạng thái</th>
                            <th class="px-5 py-3 text-left text-gray-400 font-medium text-xs uppercase tracking-wider">Mô tả</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-gray-300">
                                Chưa có giao dịch nào
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</x-app-layout>
