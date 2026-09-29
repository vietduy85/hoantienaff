{{--
    Layout riêng cho Module Thẻ tín dụng (/thetindung/*).

    Kế thừa layout chính hiện tại (layouts.app → layouts.navigation) để tái sử dụng
    branding/header sẵn có. KHÔNG sửa cấu trúc layout đang chạy.

    Grid: desktop = CONTENT | SIDEBAR PHẢI. Mobile = sidebar gọn lên trên content.

    Props (title / subtitle / active) khai báo tại App\View\Components\CreditCard\Layout.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start gap-3">
            <span class="text-2xl sm:text-3xl leading-none mt-0.5" aria-hidden="true">💳</span>
            <div class="min-w-0">
                <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                    {{ $title }}
                </h2>
                @if ($subtitle)
                    <p class="text-sm text-gray-500 mt-0.5">{{ $subtitle }}</p>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="bg-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 sm:py-6">

            {{-- Sidebar: mobile = ngang (cuộn ngang), desktop = cột phải --}}
            @include('credit-card.partials.sidebar', ['active' => $active])

            <div class="mt-5 lg:mt-0 lg:grid lg:grid-cols-[minmax(0,1fr)_260px] lg:gap-6 lg:items-start">
                {{-- Main content --}}
                <div class="min-w-0 space-y-4 sm:space-y-5 order-1">
                    {{ $slot }}
                </div>

                {{-- Sidebar (desktop, bên phải) --}}
                <aside class="hidden lg:block order-2">
                    <div class="sticky top-20 space-y-4">
                        @include('credit-card.partials.module-info')
                    </div>
                </aside>
            </div>

        </div>
    </div>
</x-app-layout>
