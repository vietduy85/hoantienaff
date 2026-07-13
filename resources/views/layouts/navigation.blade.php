<nav x-data="{ open: false, showSupport: false }" class="bg-white border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-14 sm:h-16">
            <div class="flex items-center gap-5 sm:gap-6">
                <!-- Trang chủ -->
                <a href="{{ route('dashboard') }}"
                   class="font-semibold text-emerald-600 hover:text-emerald-700 active:text-emerald-800 transition-colors text-sm sm:text-base whitespace-nowrap"
                   aria-label="Trang chủ">
                    Trang chủ
                </a>

                <!-- Hỗ trợ -->
                <button @click="showSupport = true"
                        class="font-semibold text-emerald-600 hover:text-emerald-700 active:text-emerald-800 transition-colors text-sm sm:text-base whitespace-nowrap"
                        aria-label="Hỗ trợ">
                    Hỗ trợ
                </button>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>
                </div>
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

                    <x-dropdown-link :href="route('admin.affiliate-short-link.index')">
                        {{ __('Tạo Short Link Affiliate') }}
                    </x-dropdown-link>

                    <x-dropdown-link :href="route('admin.affiliate-config.index')">
                        {{ __('Cấu hình tạo Link') }}
                    </x-dropdown-link>

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

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
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

                    <x-responsive-nav-link :href="route('admin.affiliate-short-link.index')">
                        {{ __('Tạo Short Link Affiliate') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link :href="route('admin.affiliate-config.index')">
                        {{ __('Cấu hình tạo Link') }}
                    </x-responsive-nav-link>

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
</nav>
