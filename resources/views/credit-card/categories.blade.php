{{-- /thetindung/danh-muc — placeholder Giai đoạn 2 --}}
<x-credit-card.layout
    title="Danh mục"
    subtitle="Danh mục thẻ tín dụng và ngân hàng phát hành"
    active="categories">

    @include('credit-card.partials.placeholder', [
        'title' => 'Danh mục',
        'icon' => '📁',
    ])

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
        <h4 class="font-semibold text-gray-800 text-sm">Danh sách danh mục</h4>
        <p class="text-sm text-gray-500">Chưa có dữ liệu</p>
    </div>

</x-credit-card.layout>
