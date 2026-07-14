<x-layouts.public :pageTitle="$pageTitle" :pageDescription="$pageDescription" :canonical="$canonical">
    {{-- Hero --}}
    <section class="bg-gradient-to-b from-emerald-50 to-white py-16 sm:py-20">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <span class="text-5xl mb-4 block">🚀</span>
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 tracking-tight">
                Cách hoạt động
            </h1>
            <p class="mt-3 text-lg sm:text-xl text-emerald-600 font-semibold">
                6 bước đơn giản để nhận tiền hoàn
            </p>
            <p class="mt-4 text-gray-500 leading-relaxed max-w-xl mx-auto">
                Hoàn Tiền Aff giúp bạn nhận lại tiền từ mỗi đơn hàng mua sắm online. Chỉ cần làm theo các bước dưới đây.
            </p>
        </div>
    </section>

    {{-- Steps --}}
    <section class="py-12 sm:py-16">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="space-y-10">

                {{-- Step 1 --}}
                <div class="flex gap-5">
                    <div class="flex flex-col items-center">
                        <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 font-bold text-lg flex items-center justify-center shrink-0">1</div>
                        <div class="w-0.5 flex-1 bg-emerald-100 mt-2"></div>
                    </div>
                    <div class="pb-8">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="text-2xl">🔐</span>
                            <h2 class="text-xl font-bold text-gray-900">Đăng nhập tài khoản</h2>
                        </div>
                        <p class="text-gray-600 leading-relaxed">
                            Truy cập <a href="/" class="text-emerald-600 hover:underline">hoantien.xyz</a> và đăng nhập bằng tài khoản Google. Quá trình đăng ký hoàn toàn miễn phí và chỉ mất vài giây.
                        </p>
                    </div>
                </div>

                {{-- Step 2 --}}
                <div class="flex gap-5">
                    <div class="flex flex-col items-center">
                        <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 font-bold text-lg flex items-center justify-center shrink-0">2</div>
                        <div class="w-0.5 flex-1 bg-emerald-100 mt-2"></div>
                    </div>
                    <div class="pb-8">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="text-2xl">🔗</span>
                            <h2 class="text-xl font-bold text-gray-900">Tạo link hoàn tiền</h2>
                        </div>
                        <p class="text-gray-600 leading-relaxed">
                            Copy link sản phẩm từ Shopee, Lazada, TikTok Shop, Agoda, Booking.com hoặc Traveloka. Dán vào ô tạo link trên Dashboard và nhấn <strong>Tạo link</strong>. Hệ thống sẽ tạo link hoàn tiền cho bạn trong vài giây.
                        </p>
                    </div>
                </div>

                {{-- Step 3 --}}
                <div class="flex gap-5">
                    <div class="flex flex-col items-center">
                        <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 font-bold text-lg flex items-center justify-center shrink-0">3</div>
                        <div class="w-0.5 flex-1 bg-emerald-100 mt-2"></div>
                    </div>
                    <div class="pb-8">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="text-2xl">🛒</span>
                            <h2 class="text-xl font-bold text-gray-900">Mua sắm qua link</h2>
                        </div>
                        <p class="text-gray-600 leading-relaxed">
                            Nhấp vào nút <strong>Mua ngay</strong> trên Dashboard hoặc link hoàn tiền bạn vừa tạo. Bạn sẽ được chuyển đến sàn thương mại điện tử và mua sắm <strong>như bình thường</strong>. Giá sản phẩm không thay đổi.
                        </p>
                    </div>
                </div>

                {{-- Step 4 --}}
                <div class="flex gap-5">
                    <div class="flex flex-col items-center">
                        <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 font-bold text-lg flex items-center justify-center shrink-0">4</div>
                        <div class="w-0.5 flex-1 bg-emerald-100 mt-2"></div>
                    </div>
                    <div class="pb-8">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="text-2xl">📦</span>
                            <h2 class="text-xl font-bold text-gray-900">Đơn hàng được ghi nhận</h2>
                        </div>
                        <p class="text-gray-600 leading-relaxed">
                            Sau khi hoàn tất thanh toán, hệ thống affiliate của sàn sẽ ghi nhận đơn hàng. Đơn hàng sẽ hiển thị trên Dashboard với trạng thái <strong>Đang xử lý</strong>. Thời gian chờ đợi tùy thuộc vào từng sàn (thường 7-30 ngày).
                        </p>
                    </div>
                </div>

                {{-- Step 5 --}}
                <div class="flex gap-5">
                    <div class="flex flex-col items-center">
                        <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 font-bold text-lg flex items-center justify-center shrink-0">5</div>
                        <div class="w-0.5 flex-1 bg-emerald-100 mt-2"></div>
                    </div>
                    <div class="pb-8">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="text-2xl">💰</span>
                            <h2 class="text-xl font-bold text-gray-900">Nhận tiền hoàn</h2>
                        </div>
                        <p class="text-gray-600 leading-relaxed">
                            Khi đơn hàng được xác nhận hoàn thành, tiền hoàn sẽ được cộng vào <strong>Ví tiền</strong> của bạn. Bạn có thể kiểm tra số dư và lịch sử giao dịch bất cứ lúc nào trên Dashboard.
                        </p>
                    </div>
                </div>

                {{-- Step 6 --}}
                <div class="flex gap-5">
                    <div class="flex flex-col items-center">
                        <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 font-bold text-lg flex items-center justify-center shrink-0">6</div>
                    </div>
                    <div class="pb-0">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="text-2xl">🏦</span>
                            <h2 class="text-xl font-bold text-gray-900">Rút tiền</h2>
                        </div>
                        <p class="text-gray-600 leading-relaxed">
                            Khi số dư ví đạt tối thiểu <strong>50.000đ</strong>, bạn có thể yêu cầu rút tiền về tài khoản ngân hàng. Tiền sẽ được chuyển trong 1-3 ngày làm việc.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Tips --}}
    <section class="py-12 sm:py-16 bg-gray-50">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-2xl font-bold text-gray-900 text-center mb-10">Mẹo để nhận được nhiều tiền hoàn hơn</h2>
            <div class="grid gap-6 sm:grid-cols-2">
                <div class="bg-white rounded-xl p-5 border border-gray-100">
                    <div class="text-2xl mb-2">⏰</div>
                    <h3 class="font-semibold text-gray-900 mb-1">Luôn tạo link trước khi mua</h3>
                    <p class="text-sm text-gray-500">Đơn hàng chỉ được ghi nhận nếu bạn tạo link hoàn tiền <strong>trước</strong> khi thanh toán.</p>
                </div>
                <div class="bg-white rounded-xl p-5 border border-gray-100">
                    <div class="text-2xl mb-2">🍪</div>
                    <h3 class="font-semibold text-gray-900 mb-1">Cookie có thời hạn 30 ngày</h3>
                    <p class="text-sm text-gray-500">Sau khi nhấp link, bạn có tối đa 30 ngày để hoàn tất đơn hàng.</p>
                </div>
                <div class="bg-white rounded-xl p-5 border border-gray-100">
                    <div class="text-2xl mb-2">📱</div>
                    <h3 class="font-semibold text-gray-900 mb-1">Không mua trong ứng dụng</h3>
                    <p class="text-sm text-gray-500">Không mua hàng qua ứng dụng Zalo, Facebook hay Messenger. Hãy mở trình duyệt Safari hoặc Chrome.</p>
                </div>
                <div class="bg-white rounded-xl p-5 border border-gray-100">
                    <div class="text-2xl mb-2">✅</div>
                    <h3 class="font-semibold text-gray-900 mb-1">Đơn hàng phải thành công</h3>
                    <p class="text-sm text-gray-500">Đơn bị hủy hoặc trả hàng sẽ không được hoàn tiền.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="py-12 sm:py-16 bg-emerald-500">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-2xl sm:text-3xl font-bold text-white mb-4">Sẵn sàng nhận tiền hoàn?</h2>
            <p class="text-emerald-100 mb-6 max-w-lg mx-auto">Đăng ký miễn phí và bắt đầu tiết kiệm từ đơn hàng đầu tiên.</p>
            <a href="{{ route('register') }}" class="inline-flex items-center px-6 py-3 bg-white text-emerald-600 font-bold rounded-xl hover:bg-emerald-50 transition-colors shadow-lg">
                Đăng ký ngay
            </a>
        </div>
    </section>
</x-layouts.public>
