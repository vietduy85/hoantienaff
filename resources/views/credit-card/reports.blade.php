{{-- /thetindung/bao-cao — placeholder Giai đoạn 2 --}}
<x-credit-card.layout
    title="Báo cáo"
    subtitle="Theo dõi hạn mức, chi tiêu và lịch thanh toán"
    active="reports">

    @include('credit-card.partials.placeholder', [
        'title' => 'Báo cáo',
        'icon' => '📊',
    ])

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
            <p class="text-xs sm:text-sm text-gray-500">Chi tiêu theo tháng</p>
            <p class="text-xl font-bold text-gray-300">—</p>
            <p class="text-xs text-gray-400">Chưa có dữ liệu</p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
            <p class="text-xs sm:text-sm text-gray-500">Lịch thanh toán sắp tới</p>
            <p class="text-xl font-bold text-gray-300">—</p>
            <p class="text-xs text-gray-400">Chưa có dữ liệu</p>
        </div>
    </div>

</x-credit-card.layout>
