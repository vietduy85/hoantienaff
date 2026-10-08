{{--
    Layout riêng cho Module Thẻ tín dụng (/thetindung/*).

    Kế thừa layout chính hiện tại (layouts.app → layouts.navigation) để tái sử dụng
    branding/header sẵn có. KHÔNG sửa cấu trúc layout đang chạy.

    KHÔNG render hero header (💳 + title + subtitle). Ngay dưới global navigation là
    navigation module (3 tab chính + menu phụ nhỏ), sau đó là nội dung trang.

    Props (title / subtitle / active) khai báo tại App\View\Components\CreditCard\Layout.
--}}
<x-app-layout>
    <div class="bg-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4 sm:pt-6 pb-6 sm:pb-8">

            @include('credit-card.partials.tabs', ['active' => $active])

            <div class="mt-4 sm:mt-5 space-y-4 sm:space-y-5">
                {{ $slot }}
            </div>
        </div>
    </div>
</x-app-layout>