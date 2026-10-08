{{--
    Navigation module Thẻ tín dụng: 1 nguồn cho Desktop & Mobile.
    + 3 tab chính (🏠 Tổng quan / 💳 Quản lý thẻ / 🧾 Sao kê): pill nổi bật.
    + Menu phụ nhỏ (Chính sách / Danh mục / Báo cáo / So sánh thẻ / Cài đặt) bên phải.
    Cuộn ngang khi không đủ chỗ (không wrap, không tự render lại title module).
--}}
@php
    $ccTabsMain = [
        ['key' => 'index', 'label' => 'Tổng quan', 'icon' => '🏠', 'route' => 'credit-cards.index'],
        ['key' => 'manage', 'label' => 'Quản lý thẻ', 'icon' => '💳', 'route' => 'credit-cards.manage'],
        ['key' => 'statements', 'label' => 'Sao kê', 'icon' => '🧾', 'route' => 'credit-cards.statements'],
    ];

    $ccTabsSecondary = [
        ['key' => 'policies', 'label' => 'Chính sách hoàn tiền', 'icon' => '🎯', 'route' => 'credit-cards.policies'],
        ['key' => 'categories', 'label' => 'Danh mục chi tiêu', 'icon' => '📁', 'route' => 'credit-cards.categories'],
        ['key' => 'reports', 'label' => 'Báo cáo', 'icon' => '📊', 'route' => 'credit-cards.reports'],
        ['key' => 'compare', 'label' => 'So sánh thẻ', 'icon' => '🔍', 'route' => 'credit-cards.compare'],
        ['key' => 'settings', 'label' => 'Cài đặt', 'icon' => '⚙️', 'route' => 'credit-cards.settings'],
    ];
@endphp

<nav aria-label="Menu module Thẻ tín dụng"
     class="bg-white rounded-2xl shadow-sm border border-gray-100 px-2 py-2">
    <div class="flex items-center gap-1.5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        @foreach ($ccTabsMain as $item)
            @php $isActive = $active === $item['key']; @endphp
            <a href="{{ route($item['route']) }}"
               @if ($isActive) aria-current="page" @endif
               class="shrink-0 inline-flex items-center gap-1.5 whitespace-nowrap px-3 h-9 rounded-xl text-sm font-medium transition-colors {{ $isActive ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' }}">
                <span aria-hidden="true">{{ $item['icon'] }}</span>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach

        <div class="shrink-0 flex items-center gap-0.5 ms-2 ps-3 border-s border-gray-200">
            @foreach ($ccTabsSecondary as $item)
                @php $isActive = $active === $item['key']; @endphp
                <a href="{{ route($item['route']) }}"
                   @if ($isActive) aria-current="page" @endif
                   class="inline-flex items-center gap-1 whitespace-nowrap px-2 h-8 rounded-lg text-xs sm:text-sm font-medium transition-colors {{ $isActive ? 'text-emerald-700 bg-emerald-50' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700' }}">
                    <span aria-hidden="true">{{ $item['icon'] }}</span>
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
</nav>