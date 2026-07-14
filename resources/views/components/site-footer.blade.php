<footer class="bg-gray-50 border-t border-gray-100 mt-16">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">

            {{-- Brand --}}
            <div class="sm:col-span-2 lg:col-span-1">
                <a href="/" class="flex flex-col mb-3">
                    <span class="font-bold text-emerald-600 text-lg">HoanTien.xyz</span>
                    <span class="text-xs text-gray-400 mt-0.5">Affiliate Cashback Platform</span>
                </a>
                <p class="text-sm text-gray-500 leading-relaxed">
                    Hoàn tiền khi mua sắm trực tuyến thông qua các nền tảng Affiliate.
                </p>
            </div>

            {{-- Quick Links --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Liên kết nhanh</h3>
                <ul class="space-y-2">
                    <li><a href="/" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Trang chủ</a></li>
                    <li><a href="{{ route('page.about') }}" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Giới thiệu</a></li>
                    <li><a href="{{ route('page.faq') }}" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">FAQ</a></li>
                    <li><a href="{{ route('page.contact') }}" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Liên hệ</a></li>
                    @auth
                        <li><a href="{{ route('dashboard') }}" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Dashboard</a></li>
                    @endauth
                </ul>
            </div>

            {{-- Supported Platforms --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Nền tảng hỗ trợ</h3>
                <ul class="space-y-2">
                    <li class="text-sm text-gray-500">Shopee</li>
                    <li class="text-sm text-gray-500">Lazada</li>
                    <li class="text-sm text-gray-500">TikTok Shop</li>
                    <li class="text-sm text-gray-500">Agoda</li>
                    <li class="text-sm text-gray-500">Booking.com</li>
                    <li class="text-sm text-gray-500">Traveloka</li>
                </ul>
                <p class="text-xs text-gray-400 mt-3 leading-relaxed">Các nền tảng được hỗ trợ hoặc đang trong quá trình tích hợp.</p>
            </div>

            {{-- Policies & Support --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Chính sách & Hỗ trợ</h3>
                <ul class="space-y-2">
                    <li><a href="{{ route('page.privacy') }}" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Chính sách bảo mật</a></li>
                    <li><a href="{{ route('page.terms') }}" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Điều khoản sử dụng</a></li>
                    <li><a href="{{ route('page.refund') }}" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Chính sách hoàn tiền</a></li>
                    <li><a href="{{ route('page.cookie') }}" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Cookie Policy</a></li>
                </ul>
                <div class="mt-4 space-y-2">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Hỗ trợ</h3>
                    <li class="flex items-center gap-2 list-none">
                        <span class="text-gray-400">📧</span>
                        <a href="mailto:tintuctonghop101@gmail.com" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Email</a>
                    </li>
                    <li class="flex items-center gap-2 list-none">
                        <span class="text-gray-400">💬</span>
                        <a href="https://zalo.me/0908505990" target="_blank" rel="noopener noreferrer" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Zalo</a>
                    </li>
                    <li class="flex items-center gap-2 list-none">
                        <span class="text-gray-400">📘</span>
                        <a href="https://facebook.com/hoantienaff" target="_blank" rel="noopener noreferrer" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">Facebook</a>
                    </li>
                    <p class="text-xs text-gray-400 mt-2">Thứ Hai - Thứ Bảy: 8:00 - 22:00 (GMT+7)</p>
                </div>
            </div>
        </div>

        {{-- Affiliate Disclaimer --}}
        <div class="mt-10 pt-6 border-t border-gray-200">
            <p class="text-xs text-gray-400 leading-relaxed max-w-3xl">
                HoanTien.xyz là nền tảng hoàn tiền thông qua các chương trình Affiliate. Chúng tôi không bán hàng trực tiếp. Việc hoàn tiền phụ thuộc vào dữ liệu ghi nhận từ các đối tác Affiliate.
            </p>
        </div>

        {{-- Bottom --}}
        <div class="mt-6 pt-6 border-t border-gray-200 flex flex-col sm:flex-row items-center justify-between gap-3">
            <p class="text-xs text-gray-400">
                &copy; 2026 HoanTien.xyz. All Rights Reserved.
            </p>
            <div class="flex items-center gap-4">
                <a href="{{ route('page.privacy') }}" class="text-xs text-gray-400 hover:text-emerald-600 transition-colors">Chính sách bảo mật</a>
                <a href="{{ route('page.terms') }}" class="text-xs text-gray-400 hover:text-emerald-600 transition-colors">Điều khoản sử dụng</a>
                <a href="{{ route('page.cookie') }}" class="text-xs text-gray-400 hover:text-emerald-600 transition-colors">Cookie Policy</a>
            </div>
        </div>
    </div>
</footer>
