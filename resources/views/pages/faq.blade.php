<x-layouts.public :pageTitle="$pageTitle" :pageDescription="$pageDescription" :canonical="$canonical">
    <section class="py-12 sm:py-16">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Câu hỏi thường gặp</h1>
            <p class="text-gray-500 mb-10">Giải đáp các thắc mắc phổ biến về dịch vụ hoàn tiền.</p>

            <div class="space-y-4" x-data="{ openFaq: null }">

                @php
                $faqs = [
                    ['q' => 'Tiền hoàn (cashback) là gì?', 'a' => 'Tiền hoàn là số tiền bạn nhận lại từ hoa hồng affiliate mà hệ thống nhận được từ các sàn thương mại điện tử. Khi bạn mua sắm qua link hoàn tiền, sàn trả hoa hồng cho hệ thống, và một phần hoa hồng đó được chuyển lại cho bạn dưới dạng tiền mặt.'],
                    ['q' => 'Tôi cần trả phí gì để sử dụng Hoàn Tiền Aff?', 'a' => 'Hoàn toàn miễn phí. Không phí đăng ký, không phí duy trì, không phí ẩn. Bạn chỉ nhận tiền, không mất gì.'],
                    ['q' => 'Bao lâu tôi nhận được tiền hoàn?', 'a' => 'Sau khi đơn hàng được sàn thương mại điện tử xác nhận hoàn thành (thường 7-30 ngày tùy sàn), tiền hoàn sẽ được cộng vào ví của bạn. Bạn có thể rút tiền khi số dư đạt từ 50.000đ.'],
                    ['q' => 'Tại sao đơn hàng của tôi bị từ chối hoàn tiền?', 'a' => 'Có một số lý do: đơn hàng bị hủy/trả hàng, bạn không tạo link hoàn tiền trước khi mua, affiliate network không ghi nhận đơn hàng, hoặc đơn hàng vi phạm chính sách của sàn.'],
                    ['q' => 'Tôi có cần đăng nhập để tạo link hoàn tiền không?', 'a' => 'Có, bạn cần đăng nhập để tạo link hoàn tiền và theo dõi đơn hàng. Đăng ký miễn phí bằng tài khoản Google chỉ mất vài giây.'],
                    ['q' => 'Hoàn tiền với mua hàng trực tiếp khác gì nhau?', 'a' => 'Bạn vẫn mua hàng trực tiếp trên sàn (Shopee, Lazada, etc.). Hoàn Tiền Aff chỉ tạo link giúp sàn ghi nhận đơn hàng qua affiliate, từ đó bạn nhận được tiền hoàn. Giá sản phẩm không thay đổi.'],
                    ['q' => 'Tôi có thể rút tiền về tài khoản ngân hàng nào?', 'a' => 'Bạn có thể rút tiền về bất kỳ tài khoản ngân hàng nào tại Việt Nam. Cần cung cấp đúng tên tài khoản, số tài khoản và ngân hàng.'],
                    ['q' => 'Tối thiểu bao nhiêu tiền mới rút được?', 'a' => 'Số tiền rút tối thiểu là 50.000đ. Thời gian xử lý từ 1-3 ngày làm việc.'],
                    ['q' => 'Tôi đã mua hàng nhưng quên tạo link hoàn tiền, có được hoàn không?', 'a' => 'Rất tiếc, nếu bạn đã mua hàng mà chưa tạo link hoàn tiền trước đó, đơn hàng sẽ không được ghi nhận và không nhận được tiền hoàn. Hãy nhớ tạo link trước mỗi lần mua sắm.'],
                    ['q' => 'Cookie affiliate hoạt động như thế nào?', 'a' => 'Khi bạn nhấp vào link hoàn tiền, hệ thống sẽ đặt một cookie trên trình duyệt. Cookie này giúp sàn thương mại điện tử nhận diện đơn hàng của bạn. Cookie có thời hạn tối đa 30 ngày.'],
                    ['q' => 'Tôi có thể hoàn tiền cho nhiều đơn hàng cùng lúc không?', 'a' => 'Có. Bạn có thể tạo link hoàn tiền cho bao nhiêu đơn hàng tùy thích. Mỗi đơn hàng sẽ được ghi nhận riêng biệt.'],
                    ['q' => 'Tiền hoàn có bị tính thuế không?', 'a' => 'Hoàn Tiền Aff không tư vấn về thuế. Bạn có trách nhiệm tự khai thuế theo quy định pháp luật Việt Nam nếu thu nhập affiliate đạt ngưỡng chịu thuế.'],
                    ['q' => 'Tôi quên mật khẩu thì phải làm sao?', 'a' => 'Bạn có thể sử dụng tính năng "Đăng nhập bằng Google" hoặc đặt lại mật khẩu qua email.'],
                    ['q' => 'Hoàn Tiền Aff hỗ trợ những sàn nào?', 'a' => 'Hiện tại Hoàn Tiền Aff hỗ trợ: Shopee, Lazada, TikTok Shop, Agoda, Booking.com, và Traveloka. Chúng tôi đang mở rộng thêm nhiều sàn mới.'],
                    ['q' => 'Làm sao để liên hệ hỗ trợ?', 'a' => 'Bạn có thể liên hệ qua email tintuctonghop101@gmail.com hoặc Zalo 0908 505 990. Thời gian hỗ trợ: 8:00 - 22:00 Thứ Hai - Thứ Bảy.'],
                    ['q' => 'Tôi có thể sử dụng Hoàn Tiền Aff trên điện thoại không?', 'a' => 'Có. Hoàn Tiền Aff hoạt động trên trình duyệt điện thoại (Safari, Chrome). Tuy nhiên, không mua hàng qua ứng dụng Zalo, Facebook hay Messenger vì cookie affiliate sẽ không hoạt động.'],
                    ['q' => 'Tỷ lệ hoàn tiền là bao nhiêu?', 'a' => 'Tỷ lệ hoàn tiền phụ thuộc vào từng sàn và danh mục sản phẩm. Shopee: 50-70% hoa hồng. Các sàn khác: theo chiến dịch hiện hành. Xem chi tiết tại trang Chính sách hoàn tiền.'],
                    ['q' => 'Tôi có cần cài đặt tiện ích mở rộng (extension) không?', 'a' => 'Không bắt buộc. Bạn có thể tạo link hoàn tiền trực tiếp trên Dashboard bằng cách dán link sản phẩm. Tiện ích mở rộng là tùy chọn, giúp tự động hóa quy trình.'],
                    ['q' => 'Đơn hàng bao lâu thì được xác nhận?', 'a' => 'Tùy sàn: Shopee 7-14 ngày sau khi giao hàng thành công, Lazada 14-30 ngày, TikTok Shop 7-21 ngày, Agoda/Booking.com/Traveloka theo chính sách của từng nền tảng.'],
                    ['q' => 'Tôi tạo link cho người khác mua hàng được không?', 'a' => 'Được. Bạn có thể chia sẻ link hoàn tiền cho bạn bè, người thân. Tuy nhiên, đơn hàng cần được thanh toán qua tài khoản trên sàn để affiliate network ghi nhận đúng.'],
                ];
                @endphp

                @foreach ($faqs as $i => $faq)
                <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <button @click="openFaq === {{ $i }} ? openFaq = null : openFaq = {{ $i }}"
                            class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-gray-50 transition-colors">
                        <span class="font-semibold text-gray-900 text-[15px] pr-4">{{ $faq['q'] }}</span>
                        <svg class="w-5 h-5 text-gray-400 shrink-0 transition-transform duration-200"
                             :class="{ 'rotate-180': openFaq === {{ $i }} }"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="openFaq === {{ $i }}" x-collapse x-cloak>
                        <div class="px-5 pb-4 text-gray-600 text-[15px] leading-relaxed">
                            {{ $faq['a'] }}
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-10 text-center">
                <p class="text-gray-500 text-sm mb-3">Không tìm thấy câu trả lời?</p>
                <a href="{{ route('page.contact') }}" class="inline-flex items-center px-5 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-semibold rounded-lg transition-colors">
                    Liên hệ hỗ trợ
                </a>
            </div>
        </div>
    </section>
</x-layouts.public>
