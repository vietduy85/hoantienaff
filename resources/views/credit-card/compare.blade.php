{{-- /thetindung/so-sanh — placeholder Giai đoạn 2 --}}
<x-credit-card.layout
    title="So sánh thẻ"
    subtitle="So sánh phí, hạn mức và ưu đãi giữa các thẻ"
    active="compare">

    @include('credit-card.partials.placeholder', [
        'title' => 'So sánh thẻ',
        'icon' => '🔍',
    ])

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
        <h4 class="font-semibold text-gray-800 text-sm">Bảng so sánh</h4>
        <p class="text-sm text-gray-500">Chưa có dữ liệu</p>
    </div>

</x-credit-card.layout>
