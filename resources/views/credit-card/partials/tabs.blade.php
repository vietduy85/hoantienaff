{{--
    Navigation module Thẻ tín dụng: 1 nguồn cho Desktop & Mobile.

    Thanh chính (6 mục): 🏠 Tổng quan · 🧾 Sao kê · 📊 Báo cáo · 🔎 Lựa chọn thẻ · 📖 Tra cứu · ⚙️ Cài đặt.

    "Tra cứu" nằm NGAY SAU "Lựa chọn thẻ": tra thông tin chính sách hoàn tiền và
    MCC liên quan (chỉ đọc) — xem `credit-cards.lookup`.

    "Quản lý thẻ", "Chính sách hoàn tiền" và "Danh mục" đã rời thanh chính; cả ba
    nằm trong menu xổ xuống của "Cài đặt" cùng với "Đơn vị tiền" (chính là trang
    `credit-cards.settings` hiện tại):

        💳 Quản lý thẻ · 💱 Đơn vị tiền · 🎯 Chính sách hoàn tiền · 📁 Danh mục

    Vì sao menu xổ xuống nằm NGOÀI vùng `overflow-x-auto`: vùng cuộn ngang ở thanh
    chính sẽ cắt (clip) mọi phần tử absolute/fixed nằm trong nó. Menu được render
    bằng `position: fixed` (Alpine định vị theo toạ độ nút bấm) nên không bị cắt và
    dùng CHUNG một markup cho cả desktop lẫn mobile — không nhân đôi cho từng
    viewport, không phụ thuộc hover.

    Active state: khi `$active` thuộc nhóm Cài đặt thì nút "Cài đặt" sáng lên và
    đúng một mục con tương ứng được đánh dấu (`aria-current="page"`). Các trang
    chính khác chỉ sáng mục chính của mình.
--}}
@php
    $ccTabsMain = [
        ['key' => 'index', 'label' => 'Tổng quan', 'icon' => '🏠', 'route' => 'credit-cards.index'],
        ['key' => 'statements', 'label' => 'Sao kê', 'icon' => '🧾', 'route' => 'credit-cards.statements'],
        ['key' => 'reports', 'label' => 'Báo cáo', 'icon' => '📊', 'route' => 'credit-cards.reports'],
        ['key' => 'compare', 'label' => 'Lựa chọn thẻ', 'icon' => '🔎', 'route' => 'credit-cards.compare'],
        ['key' => 'lookup', 'label' => 'Tra cứu', 'icon' => '📖', 'route' => 'credit-cards.lookup'],
    ];

    $ccSettingsMenu = [
        ['key' => 'manage', 'label' => 'Quản lý thẻ', 'icon' => '💳', 'route' => 'credit-cards.manage'],
        ['key' => 'settings', 'label' => 'Đơn vị tiền', 'icon' => '💱', 'route' => 'credit-cards.settings'],
        ['key' => 'policies', 'label' => 'Chính sách hoàn tiền', 'icon' => '🎯', 'route' => 'credit-cards.policies'],
        ['key' => 'categories', 'label' => 'Danh mục', 'icon' => '📁', 'route' => 'credit-cards.categories'],
    ];

    $isSettingsActive = in_array($active, array_column($ccSettingsMenu, 'key'), true);
@endphp

<nav aria-label="Menu module Thẻ tín dụng"
     class="bg-white rounded-2xl shadow-sm border border-gray-100 px-2 py-2"
     x-data="{ settingsOpen: false }"
     @click.outside="settingsOpen = false"
     @keydown.escape.window="settingsOpen = false; $refs.settingsTrigger && $refs.settingsTrigger.focus()"
     @resize.window="settingsOpen = false">

    <div class="flex items-center gap-1.5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
         @scroll="settingsOpen = false">
        @foreach ($ccTabsMain as $item)
            @php $isActive = $active === $item['key']; @endphp
            <a href="{{ route($item['route']) }}"
               @if ($isActive) aria-current="page" @endif
               class="shrink-0 inline-flex items-center gap-1.5 whitespace-nowrap px-3 h-9 rounded-xl text-sm font-medium transition-colors {{ $isActive ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' }}">
                <span aria-hidden="true">{{ $item['icon'] }}</span>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach

        {{-- "Cài đặt" là NÚT mở menu, không phải link: nó không đại diện một trang
             nào cả. Nút sáng khi đang ở bất kỳ trang con nào của Cài đặt. --}}
        <button type="button"
                x-ref="settingsTrigger"
                data-testid="cc-settings-trigger"
                class="shrink-0 inline-flex items-center gap-1.5 whitespace-nowrap px-3 h-9 rounded-xl text-sm font-medium transition-colors {{ $isSettingsActive ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' }}"
                @if ($isSettingsActive) data-active="true" @endif
                aria-haspopup="true"
                :aria-expanded="settingsOpen ? 'true' : 'false'"
                @click="
                    settingsOpen = ! settingsOpen;
                    if (settingsOpen) {
                        $nextTick(() => {
                            const trigger = $refs.settingsTrigger;
                            const menu = $refs.settingsMenu;
                            if (! trigger || ! menu) return;
                            const rect = trigger.getBoundingClientRect();
                            const width = menu.offsetWidth || 224;
                            let left = rect.right - width;
                            if (left < 8) left = 8;
                            const maxLeft = window.innerWidth - width - 8;
                            if (left > maxLeft) left = maxLeft;
                            menu.style.left = left + 'px';
                            menu.style.top = (rect.bottom + 6) + 'px';
                        });
                    }
                ">
            <span aria-hidden="true">⚙️</span>
            <span>Cài đặt</span>
            <svg class="h-3.5 w-3.5 transition-transform" :class="settingsOpen ? 'rotate-180' : ''"
                 viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
        </button>
    </div>

    {{-- Menu xổ xuống dùng chung cho desktop & mobile. `position: fixed` nên thoát
         khỏi vùng cuộn ngang phía trên (không bị cắt); Alpine đặt `left/top` theo
         toạ độ nút bấm mỗi lần mở. Mặc định ĐÓNG khi tải trang (x-cloak + x-show). --}}
    <ul x-ref="settingsMenu"
        x-show="settingsOpen"
        x-cloak
        x-transition.opacity.duration.100ms
        data-testid="cc-settings-menu"
        aria-label="Menu Cài đặt"
        class="fixed z-50 min-w-[14rem] max-w-[calc(100vw-1rem)] rounded-xl border border-gray-200 bg-white p-1 shadow-lg">
        @foreach ($ccSettingsMenu as $item)
            @php $isActive = $active === $item['key']; @endphp
            <li>
                <a href="{{ route($item['route']) }}"
                   data-testid="cc-settings-item-{{ $item['key'] }}"
                   @if ($isActive) aria-current="page" @endif
                   @click="settingsOpen = false"
                   class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors {{ $isActive ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-700 hover:bg-gray-100' }}">
                    <span aria-hidden="true">{{ $item['icon'] }}</span>
                    <span>{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
