@php
$travelPlatforms = collect(config('travel.platforms'))
    ->where('enabled', true)
    ->values();
    $pendingWithdrawCount = (int) ($pendingWithdrawCount ?? 0);
    $shopeeFashionBadgeCount = (int) ($shopeeFashionBadgeCount ?? 0);
    $navUserInitial = mb_strtoupper(mb_substr((string) (auth()->user()->name ?? 'U'), 0, 1));
    $navIsHome = request()->routeIs('dashboard');
    $navIsWallet = request()->routeIs('wallet.*', 'orders.*', 'referrals.*', 'profile.*');
    $navIsCard = request()->routeIs('credit-cards.*');
    $navIsMore = request()->routeIs('guide.*', 'price-comparison.*', 'promotion-news.*');
@endphp

<nav x-data="{ open: false, showMore: false, showSupport: false, showTravel: false }" class="bg-white border-b border-gray-100" style="padding-top: env(safe-area-inset-top, 0px);">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-14 sm:h-16">
            {{-- Mobile: thanh trên chỉ còn logo + chấm thông báo; menu chính nằm ở thanh dưới. --}}
            <a href="{{ route('dashboard') }}" class="sm:hidden flex items-center gap-2 min-w-0" aria-label="Mua gì cũng được hoàn">
                <img src="/icon-192.png" width="36" height="36" alt="" aria-hidden="true" class="w-9 h-9 rounded-xl shrink-0">
                <span class="font-extrabold text-base text-orange-600 leading-tight truncate">Mua gì cũng được hoàn</span>
                @if ($shopeeFashionBadgeCount > 0)
                    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-red-500 text-white text-[11px] font-semibold leading-none tabular-nums">{{ $shopeeFashionBadgeCount > 99 ? '99+' : $shopeeFashionBadgeCount }}</span>
                @endif
            </a>

            {{-- Desktop: menu chính nằm ngang ở thanh trên:
                 Hoàn tiền → Đơn & Ví → Thẻ tín dụng → So sánh giá → Tin tức KM → Đặt vé → Hỗ trợ.
                 Mobile dùng thanh điều hướng dưới (xem cuối file). --}}
            <div class="hidden sm:flex flex-1 min-w-0 items-center gap-5 sm:gap-6 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                 x-init="$nextTick(() => { const el = $el.querySelector('[aria-current=\"page\"]'); if (el && el.scrollIntoView) el.scrollIntoView({ inline: 'nearest', block: 'nearest' }); })">

                <!-- Hoàn tiền (trang chủ) -->
                <a href="{{ route('dashboard') }}"
                   class="shrink-0 inline-flex items-center gap-1.5 font-semibold text-emerald-600 hover:text-emerald-700 active:text-emerald-800 transition-colors text-sm sm:text-base whitespace-nowrap"
                   aria-label="Hoàn tiền{{ $pendingWithdrawCount > 0 ? ' ('.$pendingWithdrawCount.' yêu cầu rút tiền đang chờ xử lý)' : '' }}{{ $shopeeFashionBadgeCount > 0 ? ' ('.$shopeeFashionBadgeCount.' đơn Shopee thời trang cần cào xu)' : '' }}">
                    Hoàn tiền
                    @can('withdrawals.view')
                        @if ($pendingWithdrawCount > 0)
                            <span data-testid="pending-withdraw-badge"
                                  title="{{ $pendingWithdrawCount }} yêu cầu rút tiền đang chờ xử lý"
                                  class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-red-500 text-white text-[11px] font-semibold leading-none tabular-nums">
                                {{ $pendingWithdrawCount > 99 ? '99+' : $pendingWithdrawCount }}
                            </span>
                        @endif
                    @endcan
                    @if ($shopeeFashionBadgeCount > 0)
                        <span data-testid="shopee-fashion-badge"
                              data-shopee-fashion-badge
                              title="{{ $shopeeFashionBadgeCount }} đơn Shopee thời trang cần cào xu"
                              class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-red-500 text-white text-[11px] font-semibold leading-none tabular-nums ring-2 ring-white">
                            {{ $shopeeFashionBadgeCount > 99 ? '99+' : $shopeeFashionBadgeCount }}
                        </span>
                    @endif
                </a>

                <!-- Đơn & Ví -->
                <a href="{{ route('wallet.index') }}"
                   @if ($navIsWallet) aria-current="page" @endif
                   @class([
                       'shrink-0 font-semibold text-emerald-600 hover:text-emerald-700 active:text-emerald-800 transition-colors text-sm sm:text-base whitespace-nowrap',
                       'text-emerald-700' => $navIsWallet,
                   ])
                   aria-label="Đơn và Ví">
                    Đơn &amp; Ví
                </a>

                <!-- Thẻ tín dụng -->
                <a href="{{ route('credit-cards.index') }}"
                   @if (request()->routeIs('credit-cards.*')) aria-current="page" @endif
                   @class([
                       'shrink-0 font-semibold text-emerald-600 hover:text-emerald-700 active:text-emerald-800 transition-colors text-sm sm:text-base whitespace-nowrap',
                       'text-emerald-700' => request()->routeIs('credit-cards.*'),
                   ])
                   aria-label="Thẻ tín dụng">
                    💳 Thẻ tín dụng
                </a>

                <!-- So sánh giá -->
                <a href="{{ route('price-comparison.index') }}"
                   class="shrink-0 font-semibold text-emerald-600 hover:text-emerald-700 active:text-emerald-800 transition-colors text-sm sm:text-base whitespace-nowrap"
                   aria-label="So sánh giá">
                    🔎 So sánh giá
                </a>

                <!-- Tin tức KM -->
                <a href="{{ route('promotion-news.index') }}"
                   class="shrink-0 font-semibold text-emerald-600 hover:text-emerald-700 active:text-emerald-800 transition-colors text-sm sm:text-base whitespace-nowrap"
                   aria-label="Tin tức KM">
                    📰 Tin tức KM
                </a>

                <!-- Đặt vé máy bay, khách sạn (Desktop: dropdown; Mobile: bottom-sheet bên dưới) -->
                <div class="relative shrink-0" @click.away="showTravel = false">
                    <button @click="showTravel = !showTravel"
                            class="font-semibold text-emerald-600 hover:text-emerald-700 active:text-emerald-800 transition-colors text-sm sm:text-base whitespace-nowrap inline-flex items-center gap-1"
                            aria-label="Đặt vé máy bay, khách sạn">
                        Đặt vé máy bay, khách sạn
                        <svg class="w-3 h-3" :class="{'rotate-180': showTravel}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    {{-- Dropdown chỉ cho >=sm (mobile dùng bottom-sheet, tránh bị cắt bởi overflow). --}}
                    <div class="hidden sm:block absolute left-0 mt-2 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-1 z-50">
                        <div x-show="showTravel"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95">
                            @foreach($travelPlatforms as $platform)
                                <a href="{{ $platform['url'] }}"
                                   target="_blank" rel="noopener noreferrer"
                                   class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                                    {{ $platform['name'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>

                <!-- Hỗ trợ -->
                <button @click="showSupport = true"
                        class="shrink-0 font-semibold text-emerald-600 hover:text-emerald-700 active:text-emerald-800 transition-colors text-sm sm:text-base whitespace-nowrap"
                        aria-label="Hỗ trợ">
                    Hỗ trợ
                </button>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Tài khoản') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('wallet.index')">
                            {{ __('Ví tiền') }}
                        </x-dropdown-link>

                        @can('credit-cards.view')
                            <x-dropdown-link :href="route('admin.credit-card.system-categories.index')" :active="request()->routeIs('admin.credit-card.system-categories.*')">
                                Danh mục hệ thống
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('admin.credit-card.system-combos.index')" :active="request()->routeIs('admin.credit-card.system-combos.*')">
                                Combo danh mục
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('admin.credit-card.spend-qualifications.index')" :active="request()->routeIs('admin.credit-card.spend-qualifications.*')">
                                ⛔ Mẫu điều kiện hoàn tiền đặc biệt
                            </x-dropdown-link>
                        @endcan

                        @can('credit-cards.view')
                            <x-dropdown-link :href="route('admin.credit-card-policies.index')" :active="request()->routeIs('admin.credit-card-policies.*')">
                                🎯 {{ __('Quản lý chính sách hoàn tiền') }}
                            </x-dropdown-link>
                        @endcan

                        @can('credit-cards.view')
                            <x-dropdown-link :href="route('admin.credit-card.mcc.index')" :active="request()->routeIs('admin.credit-card.mcc.*')">
                                🧩 {{ __('Quản lý MCC') }}
                            </x-dropdown-link>
                        @endcan

                        <x-dropdown-link :href="route('referrals.index')">
                            {{ __('Giới thiệu bạn bè') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('orders.index')">
                            {{ __('Tra cứu đơn hàng') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('guide.index')">
                            {{ __('Hướng dẫn') }}
                        </x-dropdown-link>

                @can('withdrawals.view')
                    <x-dropdown-link :href="route('admin.withdraw-requests.index')">
                        {{ __('Quản lý rút tiền') }}
                    </x-dropdown-link>
                @endcan

                @role('Admin|Operator')
                    <x-dropdown-link :href="route('admin.affiliate-short-link.index')">
                        {{ __('Tạo Short Link Affiliate') }}
                    </x-dropdown-link>
                @endrole

                @role('Admin|Operator')
                    <x-dropdown-link :href="route('admin.tiktok-order-sync.index')">
                        {{ __('Đồng bộ đơn hàng TikTok, Lazada, ShopeeFood & Shopee') }}
                    </x-dropdown-link>
                @endrole

                @role('Admin|Operator')
                    <x-dropdown-link :href="route('admin.promotion-news.index')">
                        {{ __('Tin tức khuyến mãi') }}
                    </x-dropdown-link>
                @endrole

                @role('Admin')
                    <x-dropdown-link :href="route('admin.affiliate-config.index')">
                        {{ __('Cấu hình tạo Link') }}
                    </x-dropdown-link>
                @endrole

                @role('Admin')
                    <x-dropdown-link :href="route('admin.shopee-fashion-alert-config.index')">
                        {{ __('Cập nhật link cào xu') }}
                    </x-dropdown-link>
                @endrole

                @can('users.view')
                    <x-dropdown-link :href="route('admin.users.index')">
                        {{ __('Quản lý người dùng') }}
                    </x-dropdown-link>
                @endcan

                @can('users.view')
                    <x-dropdown-link :href="route('admin.referrals.statistics')">
                        {{ __('Thống kê giới thiệu') }}
                    </x-dropdown-link>
                @endcan

                @can('withdrawals.view')
                    <x-dropdown-link :href="route('admin.finance.index')">
                        {{ __('Quản lý tài chính') }}
                    </x-dropdown-link>
                @endcan

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Đăng xuất') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Mobile: avatar mở "Thêm" -->
            <div class="flex items-center sm:hidden">
                <button @click="showMore = true" class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-emerald-700 text-white font-bold text-lg focus:outline-none" aria-label="Thêm">
                    {{ $navUserInitial }}
                </button>
            </div>
        </div>
    </div>

    {{-- Mobile: bottom sheet "Thêm" (tài khoản, hướng dẫn, công cụ phụ, admin). --}}
    <div class="sm:hidden">
    <x-bottom-sheet show="showMore" title="Thêm">
        <div class="grid grid-cols-2 gap-2">
            <a href="{{ route('price-comparison.index') }}" class="flex items-center gap-2 bg-gray-50 rounded-2xl px-4 h-12 text-sm font-medium text-gray-700">🔎 So sánh giá</a>
            <a href="{{ route('promotion-news.index') }}" class="flex items-center gap-2 bg-gray-50 rounded-2xl px-4 h-12 text-sm font-medium text-gray-700">📰 Tin tức KM</a>
            <button type="button" @click="showMore = false; showTravel = true" class="flex items-center gap-2 bg-gray-50 rounded-2xl px-4 h-12 text-sm font-medium text-gray-700 text-left">✈️ Đặt vé, khách sạn</button>
            <button type="button" @click="showMore = false; showSupport = true" class="flex items-center gap-2 bg-gray-50 rounded-2xl px-4 h-12 text-sm font-medium text-gray-700 text-left">💬 Hỗ trợ</button>
        </div>
        <div>
        {{-- Các mục chính (Trang chủ / Thẻ tín dụng / Hỗ trợ / So sánh giá / Tin tức KM / Đặt vé máy bay,
             khách sạn) đã nằm ở thanh ngang phía trên cho mọi breakpoint — không lặp lại trong hamburger.
             Hamburger chỉ giữ phần tài khoản/cài đặt/admin. --}}
        <div class="pt-2 pb-3 space-y-1">
            @can('credit-cards.view')
                <x-responsive-nav-link :href="route('admin.credit-card.system-categories.index')" :active="request()->routeIs('admin.credit-card.system-categories.*')">
                    Danh mục hệ thống
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.credit-card.system-combos.index')" :active="request()->routeIs('admin.credit-card.system-combos.*')">
                    Combo danh mục
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.credit-card.spend-qualifications.index')" :active="request()->routeIs('admin.credit-card.spend-qualifications.*')">
                    ⛔ Mẫu điều kiện hoàn tiền đặc biệt
                </x-responsive-nav-link>
            @endcan

            @can('credit-cards.view')
                <x-responsive-nav-link :href="route('admin.credit-card-policies.index')" :active="request()->routeIs('admin.credit-card-policies.*')">
                    🎯 {{ __('Quản lý chính sách hoàn tiền') }}
                </x-responsive-nav-link>
            @endcan

            @can('credit-cards.view')
                <x-responsive-nav-link :href="route('admin.credit-card.mcc.index')" :active="request()->routeIs('admin.credit-card.mcc.*')">
                    🧩 {{ __('Quản lý MCC') }}
                </x-responsive-nav-link>
            @endcan
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Tài khoản') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('wallet.index')">
                    {{ __('Ví tiền') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('referrals.index')">
                    {{ __('Giới thiệu bạn bè') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('orders.index')">
                    {{ __('Tra cứu đơn hàng') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('guide.index')">
                    {{ __('Hướng dẫn') }}
                </x-responsive-nav-link>

                @can('withdrawals.view')
                    <x-responsive-nav-link :href="route('admin.withdraw-requests.index')">
                        {{ __('Quản lý rút tiền') }}
                    </x-responsive-nav-link>
                @endcan

                @role('Admin|Operator')
                    <x-responsive-nav-link :href="route('admin.affiliate-short-link.index')">
                        {{ __('Tạo Short Link Affiliate') }}
                    </x-responsive-nav-link>
                @endrole

                @role('Admin|Operator')
                    <x-responsive-nav-link :href="route('admin.tiktok-order-sync.index')">
                        {{ __('Đồng bộ đơn hàng TikTok, Lazada, ShopeeFood & Shopee') }}
                    </x-responsive-nav-link>
                @endrole

                @role('Admin|Operator')
                    <x-responsive-nav-link :href="route('admin.promotion-news.index')">
                        {{ __('Tin tức khuyến mãi') }}
                    </x-responsive-nav-link>
                @endrole

                @role('Admin')
                    <x-responsive-nav-link :href="route('admin.affiliate-config.index')">
                        {{ __('Cấu hình tạo Link') }}
                    </x-responsive-nav-link>
                @endrole

                @role('Admin')
                    <x-responsive-nav-link :href="route('admin.shopee-fashion-alert-config.index')">
                        {{ __('Cập nhật link cào xu') }}
                    </x-responsive-nav-link>
                @endrole

                @can('users.view')
                    <x-responsive-nav-link :href="route('admin.users.index')">
                        {{ __('Quản lý người dùng') }}
                    </x-responsive-nav-link>
                @endcan

                @can('users.view')
                    <x-responsive-nav-link :href="route('admin.referrals.statistics')">
                        {{ __('Thống kê giới thiệu') }}
                    </x-responsive-nav-link>
                @endcan

                @can('withdrawals.view')
                    <x-responsive-nav-link :href="route('admin.finance.index')">
                        {{ __('Quản lý tài chính') }}
                    </x-responsive-nav-link>
                @endcan

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Đăng xuất') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
        </div>
    </x-bottom-sheet>
    </div>

    {{-- Bottom Sheet: Hỗ trợ --}}
    <x-bottom-sheet show="showSupport" title="Hỗ trợ">
        {{-- Zalo cá nhân --}}
        <div class="bg-gray-50 rounded-2xl p-4 space-y-3">
            <div class="flex items-center gap-2">
                <span class="text-lg" aria-hidden="true">💬</span>
                <span class="font-semibold text-gray-800 text-sm">Zalo hỗ trợ</span>
            </div>
            <p class="text-sm text-gray-500 font-mono tracking-wide">090***990</p>
            <a href="https://zalo.me/0908505990"
               target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center justify-center w-full h-11 bg-blue-500 hover:bg-blue-600 active:bg-blue-700 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
               aria-label="Mở Zalo hỗ trợ">
                Mở Zalo
            </a>
        </div>

        {{-- Nhóm Zalo --}}
        <div class="bg-gray-50 rounded-2xl p-4 space-y-3">
            <div class="flex items-center gap-2">
                <span class="text-lg" aria-hidden="true">👥</span>
                <span class="font-semibold text-gray-800 text-sm">Nhóm Săn Voucher, Deal Hot Shopee</span>
            </div>
            <div class="text-sm text-gray-500 leading-relaxed space-y-1">
                <p>Tham gia nhóm để nhận:</p>
                <ul class="list-disc list-inside space-y-0.5">
                    <li>Voucher Shopee mới nhất</li>
                    <li>Mã giảm giá</li>
                    <li>Deal Hot mỗi ngày</li>
                    <li>Thông báo cập nhật HoanTien.xyz</li>
                </ul>
            </div>
            <a href="https://zalo.me/g/zrlr2wd7pxkeqnjjfyus"
               target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center justify-center w-full h-11 bg-blue-500 hover:bg-blue-600 active:bg-blue-700 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
               aria-label="Tham gia nhóm Zalo">
                Tham gia nhóm
            </a>
        </div>

        {{-- Email --}}
        <div class="bg-gray-50 rounded-2xl p-4 space-y-3">
            <div class="flex items-center gap-2">
                <span class="text-lg" aria-hidden="true">📧</span>
                <span class="font-semibold text-gray-800 text-sm">Email</span>
            </div>
            <p class="text-sm text-gray-500 select-all">tintuctonghop101@gmail.com</p>
            <a href="mailto:tintuctonghop101@gmail.com"
               class="inline-flex items-center justify-center w-full h-11 bg-blue-500 hover:bg-blue-600 active:bg-blue-700 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
               aria-label="Gửi Email">
                Gửi Email
            </a>
        </div>

        {{-- Thông điệp --}}
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
            <p class="text-xs sm:text-sm text-amber-800 leading-relaxed">
                🎁 Mua Shopee qua HoanTien.xyz để được hoàn lại từ 50%–70% hoa hồng Affiliate (tùy từng sản phẩm và chính sách áp dụng). Nếu đã mua sắm, đừng bỏ lỡ khoản tiền hoàn dành cho bạn!
            </p>
        </div>
    </x-bottom-sheet>

    {{-- Bottom Sheet: Đặt vé máy bay, khách sạn (chỉ Mobile) --}}
    <div class="sm:hidden">
        <x-bottom-sheet show="showTravel" title="Đặt vé máy bay, khách sạn">
            <div class="space-y-2">
                @foreach($travelPlatforms as $platform)
                    <a href="{{ $platform['url'] }}"
                       target="_blank" rel="noopener noreferrer"
                       class="flex items-center justify-between bg-gray-50 rounded-2xl px-4 h-12 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                        <span>{{ $platform['name'] }}</span>
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                @endforeach
            </div>
        </x-bottom-sheet>
    </div>
    {{-- Mobile: thanh điều hướng dưới (cố định). Chừa safe-area cho iPhone. --}}
    <div class="sm:hidden fixed inset-x-0 bottom-0 z-40 bg-white border-t border-gray-200" style="padding-bottom: env(safe-area-inset-bottom, 0px);" data-testid="bottom-nav">
        <div class="grid grid-cols-4 h-16">
            @php
                $tab = fn (bool $active) => $active ? 'text-orange-600' : 'text-gray-500';
            @endphp
            <a href="{{ route('dashboard') }}" @if ($navIsHome) aria-current="page" @endif class="relative flex flex-col items-center justify-center gap-0.5 text-xs font-medium {{ $tab($navIsHome) }}">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5L12 3l9 7.5V20a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1v-9.5z"/></svg>
                Hoàn tiền
                @if ($shopeeFashionBadgeCount > 0 || $pendingWithdrawCount > 0)
                    <span class="absolute top-1.5 left-1/2 ml-2.5 w-2.5 h-2.5 rounded-full bg-red-500 ring-2 ring-white"></span>
                @endif
            </a>
            <a href="{{ route('wallet.index') }}" @if ($navIsWallet) aria-current="page" @endif class="flex flex-col items-center justify-center gap-0.5 text-xs font-medium {{ $tab($navIsWallet) }}">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h13a1 1 0 011 1v2M3 7v10a2 2 0 002 2h14a1 1 0 001-1v-3m-18-9h16a1 1 0 011 1v3m0 0h-4a2 2 0 100 4h4v-4z"/></svg>
                Đơn &amp; Ví
            </a>
            <a href="{{ route('credit-cards.index') }}" @if ($navIsCard) aria-current="page" @endif class="flex flex-col items-center justify-center gap-0.5 text-xs font-medium {{ $tab($navIsCard) }}">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm0 2.5h18M7 15h3"/></svg>
                Thẻ
            </a>
            <button type="button" @click="showMore = true" class="flex flex-col items-center justify-center gap-0.5 text-xs font-medium {{ $tab($navIsMore) }}">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h.01M12 12h.01M19 12h.01"/></svg>
                Thêm
            </button>
        </div>
    </div>
</nav>

@push('scripts')
<script>
    (function () {
        const actionUrl = new URL('{{ route('logout') }}', window.location.origin);
        const loginPath = new URL('{{ route('login') }}', window.location.origin).pathname;
        const logoutPath = actionUrl.pathname;

        function sameLogout(url) {
            try {
                return new URL(url, window.location.origin).pathname === logoutPath;
            } catch (e) {
                return false;
            }
        }

        function runLogout(form, event) {
            if (event) {
                event.preventDefault();
            }

            const token = form.querySelector('input[name="_token"]');

            fetch(actionUrl.href, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token ? token.value : ''
                },
                body: new FormData(form),
                redirect: 'follow'
            }).then(function (r) {
                if (r.status === 401 || r.status === 419) {
                    window.location.href = loginPath;
                    return;
                }
                window.location.href = r.redirected ? r.url : actionUrl.href;
            }).catch(function () {
                if (event) {
                    form.submit();
                }
            });
        }

        document.addEventListener('click', function (e) {
            const anchor = e.target.closest('a[href]');
            if (!anchor || !sameLogout(anchor.getAttribute('href'))) return;
            const form = anchor.closest('form');
            if (!form) return;
            runLogout(form, e);
        });

        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (!form || !form.method) return;
            if (form.method.toLowerCase() !== 'post' || !sameLogout(form.action)) return;
            e.preventDefault();
            runLogout(form, null);
        });
    })();
</script>
@endpush
