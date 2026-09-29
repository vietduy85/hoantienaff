{{-- /thetindung/quan-ly-the — placeholder Giai đoạn 2 --}}
<x-credit-card.layout
    title="Quản lý thẻ"
    subtitle="Thêm, sửa và quản lý các thẻ tín dụng của bạn"
    active="manage">

    @include('credit-card.partials.placeholder', [
        'title' => 'Quản lý thẻ',
        'icon' => '💳',
    ])

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
        <h4 class="font-semibold text-gray-800 text-sm">Danh sách thẻ của bạn</h4>
        <p class="text-sm text-gray-500">Chưa có dữ liệu</p>
    </div>

</x-credit-card.layout>
