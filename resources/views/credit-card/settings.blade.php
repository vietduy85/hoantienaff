{{-- /thetindung/cai-dat — placeholder Giai đoạn 2 --}}
<x-credit-card.layout
    title="Cài đặt"
    subtitle="Tuỳ chọn hiển thị và thông báo của module"
    active="settings">

    @include('credit-card.partials.placeholder', [
        'title' => 'Cài đặt',
        'icon' => '⚙️',
    ])

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
        <h4 class="font-semibold text-gray-800 text-sm">Tuỳ chọn module</h4>
        <p class="text-sm text-gray-500">Chưa có tuỳ chọn nào.</p>
    </div>

</x-credit-card.layout>
