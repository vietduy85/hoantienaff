<x-layouts.public :pageTitle="$pageTitle" :pageDescription="$pageDescription" :canonical="$canonical">
    <section class="py-12 sm:py-16">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Liên hệ</h1>
            <p class="text-gray-500 mb-10">Chúng tôi sẵn sàng hỗ trợ bạn. Hãy liên hệ qua một trong các kênh sau.</p>

            <div class="grid gap-6 sm:grid-cols-2">

                {{-- Email --}}
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <div class="text-3xl mb-3">📧</div>
                    <h2 class="font-semibold text-gray-900 mb-1">Email</h2>
                    <p class="text-sm text-gray-500 mb-3">Gửi thắc mắc qua email. Chúng tôi phản hồi trong 24 giờ.</p>
                    <a href="mailto:tintuctonghop101@gmail.com" class="inline-flex items-center px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-semibold rounded-lg transition-colors">
                        tintuctonghop101@gmail.com
                    </a>
                </div>

                {{-- Zalo --}}
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <div class="text-3xl mb-3">💬</div>
                    <h2 class="font-semibold text-gray-900 mb-1">Zalo</h2>
                    <p class="text-sm text-gray-500 mb-3">Nhắn tin trực tiếp qua Zalo để được hỗ trợ nhanh nhất.</p>
                    <a href="https://zalo.me/0908505990" target="_blank" rel="noopener noreferrer" class="inline-flex items-center px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white text-sm font-semibold rounded-lg transition-colors">
                        Mở Zalo
                    </a>
                </div>

                {{-- Facebook --}}
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <div class="text-3xl mb-3">📘</div>
                    <h2 class="font-semibold text-gray-900 mb-1">Facebook</h2>
                    <p class="text-sm text-gray-500 mb-3">Theo dõi fanpage để nhận thông tin mới nhất.</p>
                    <a href="https://facebook.com/hoantienaff" target="_blank" rel="noopener noreferrer" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg transition-colors">
                        Facebook
                    </a>
                </div>

                {{-- Support hours --}}
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <div class="text-3xl mb-3">🕐</div>
                    <h2 class="font-semibold text-gray-900 mb-1">Thời gian hỗ trợ</h2>
                    <p class="text-sm text-gray-500 mb-1">Thứ Hai - Thứ Bảy</p>
                    <p class="text-sm text-gray-500 mb-3">8:00 - 22:00 (GMT+7)</p>
                    <p class="text-xs text-gray-400">Phản hồi email trong vòng 24 giờ.</p>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
