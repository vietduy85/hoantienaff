<x-layouts.public
    :pageTitle="'Không tìm thấy trang'"
    :pageDescription="'Trang bạn tìm kiếm không tồn tại hoặc đã bị di chuyển.'"
>
    <section class="bg-gradient-to-b from-emerald-50 to-white py-20 sm:py-28">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <span class="text-6xl mb-4 block">🔍</span>
            <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 tracking-tight">404</h1>
            <p class="mt-3 text-lg sm:text-xl text-gray-500 font-medium">
                Không tìm thấy trang
            </p>
            <p class="mt-4 text-gray-400 leading-relaxed max-w-md mx-auto">
                Trang bạn đang tìm kiếm không tồn tại, đã bị xóa hoặc tạm thời không khả dụng.
            </p>
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <a href="/"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-emerald-600 text-white text-sm font-semibold rounded-xl hover:bg-emerald-700 transition-colors shadow-sm">
                    <span>←</span>
                    <span>Về trang chủ</span>
                </a>
                <a href="{{ route('page.faq') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-white text-gray-700 text-sm font-semibold rounded-xl border border-gray-200 hover:border-emerald-300 hover:text-emerald-600 transition-colors">
                    <span>Câu hỏi thường gặp</span>
                </a>
                <a href="{{ route('page.contact') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-white text-gray-700 text-sm font-semibold rounded-xl border border-gray-200 hover:border-emerald-300 hover:text-emerald-600 transition-colors">
                    <span>Liên hệ hỗ trợ</span>
                </a>
            </div>
        </div>
    </section>
</x-layouts.public>
