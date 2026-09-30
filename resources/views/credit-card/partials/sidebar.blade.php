{{--
    Sidebar / module menu của Module Thẻ tín dụng.

    - Mobile: menu ngang cuộn ngang (không làm vỡ layout iPhone).
    - Desktop: cột bên phải (render trong <aside> của layout).
    - Active state theo route hiện tại.
--}}
@php
    $ccMenu = [
        ['key' => 'index', 'label' => 'Tổng quan', 'icon' => '🏠', 'route' => 'credit-cards.index'],
        ['key' => 'manage', 'label' => 'Quản lý thẻ', 'icon' => '💳', 'route' => 'credit-cards.manage'],
        ['key' => 'policies', 'label' => 'Chính sách', 'icon' => '🎯', 'route' => 'credit-cards.policies'],
        ['key' => 'categories', 'label' => 'Danh mục chi tiêu', 'icon' => '📁', 'route' => 'credit-cards.categories'],
        ['key' => 'reports', 'label' => 'Báo cáo', 'icon' => '📊', 'route' => 'credit-cards.reports'],
        ['key' => 'compare', 'label' => 'So sánh thẻ', 'icon' => '🔍', 'route' => 'credit-cards.compare'],
        ['key' => 'settings', 'label' => 'Cài đặt', 'icon' => '⚙️', 'route' => 'credit-cards.settings'],
    ];
@endphp

{{-- Mobile: menu ngang cuộn ngang --}}
<nav aria-label="Menu module Thẻ tín dụng"
     class="lg:hidden -mx-4 px-4 sm:mx-0 sm:px-0">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-2">
        <p class="px-2 pt-1 pb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
            Thẻ tín dụng
        </p>
        <div class="flex gap-1.5 overflow-x-auto pb-1 -mb-1 snap-x">
            @foreach ($ccMenu as $item)
                @php $isActive = $active === $item['key']; @endphp
                <a href="{{ route($item['route']) }}"
                   @if ($isActive) aria-current="page" @endif
                   class="shrink-0 snap-start inline-flex items-center gap-1.5 whitespace-nowrap px-3 h-9 rounded-xl text-sm font-medium transition-colors {{ $isActive ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' }}">
                    <span aria-hidden="true">{{ $item['icon'] }}</span>
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
</nav>

{{-- Desktop: cột sidebar bên phải --}}
<div class="hidden lg:block bg-white rounded-2xl shadow-sm border border-gray-100 p-3">
    <p class="px-2 py-1.5 text-xs font-bold text-gray-800 border-b border-gray-100 mb-1.5">
        Thẻ tín dụng
    </p>
    <ul class="space-y-0.5">
        @foreach ($ccMenu as $item)
            @php $isActive = $active === $item['key']; @endphp
            <li>
                <a href="{{ route($item['route']) }}"
                   @if ($isActive) aria-current="page" @endif
                   class="flex items-center gap-2.5 px-3 h-10 rounded-xl text-sm font-medium transition-colors {{ $isActive ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100' : 'text-gray-600 hover:bg-gray-50' }}">
                    <span class="text-base leading-none" aria-hidden="true">{{ $item['icon'] }}</span>
                    <span>{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</div>
