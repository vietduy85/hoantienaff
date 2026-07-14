<x-layouts.public :pageTitle="$pageTitle" :pageDescription="$pageDescription" :canonical="$canonical">
    <section class="py-12 sm:py-16">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Câu hỏi thường gặp</h1>
            <p class="text-gray-500 mb-10">Giải đáp các thắc mắc phổ biến về dịch vụ hoàn tiền.</p>

            @php
            $faqs = [
                [
                    'q' => 'Hoàn Tiền Aff là gì?',
                    'a' => 'Hoàn Tiền Aff là nền tảng affiliate cashback giúp bạn nhận lại một phần tiền khi mua sắm trực tuyến. Bạn tạo link hoàn tiền trên Dashboard, mua hàng qua link đó trên các sàn thương mại điện tử, và nhận tiền hoàn về ví.',
                ],
                [
                    'q' => 'Sử dụng Hoàn Tiền Aff có mất phí không?',
                    'a' => 'Hoàn toàn miễn phí. Không phí đăng ký, không phí duy trì, không phí ẩn. Bạn chỉ nhận tiền, không mất gì.',
                ],
                [
                    'q' => 'Nền tảng nào được hỗ trợ?',
                    'a' => 'Hiện tại hỗ trợ 5 sàn: Shopee, Lazada, TikTok Shop, Tiki (mua sắm) và Agoda, Booking.com, Traveloka (đặt phòng khách sạn). Chúng tôi đang mở rộng thêm sàn mới.',
                ],
                [
                    'q' => 'Tôi có cần đăng nhập không?',
                    'a' => 'Có. Bạn cần đăng nhập bằng email hoặc tài khoản Google để tạo link hoàn tiền và theo dõi đơn hàng. Đăng ký miễn phí chỉ mất vài giây.',
                ],
                [
                    'q' => 'Tôi có cần cài ứng dụng không?',
                    'a' => 'Không bắt buộc. Hoàn Tiền Aff hoạt động trên trình duyệt web. Bạn cũng có thể cài tiện ích mở rộng (Chrome Extension) để tạo link nhanh hơn, nhưng không bắt buộc.',
                ],
                [
                    'q' => 'Làm sao để nhận được tiền hoàn?',
                    'a' => 'Bạn dán link sản phẩm lên Dashboard, hệ thống tạo link affiliate. Bạn mua hàng qua link đó, sàn thương mại điện tử ghi nhận đơn hàng và trả hoa hồng. Sau khi đơn hàng được xác nhận hoàn thành, tiền hoàn sẽ được cộng vào ví của bạn.',
                ],
                [
                    'q' => 'Link hoàn tiền hoạt động như thế nào?',
                    'a' => 'Khi bạn nhấp vào link hoàn tiền, hệ thống đặt cookie affiliate trên trình duyệt. Cookie này giúp sàn nhận diện đơn hàng và ghi nhận hoa hồng. Cookie có hiệu lực khoảng 30 ngày.',
                ],
                [
                    'q' => 'Bao lâu tạo được link hoàn tiền?',
                    'a' => 'Tức thời. Bạn dán link sản phẩm, hệ thống xử lý trong vài giây và hiển thị link hoàn tiền cùng số tiền hoàn ước tính.',
                ],
                [
                    'q' => 'Tôi có thể tạo link cho người khác mua hàng không?',
                    'a' => 'Được. Bạn có thể chia sẻ link hoàn tiền cho bạn bè, người thân. Họ vẫn mua hàng với giá gốc và bạn nhận tiền hoàn.',
                ],
                [
                    'q' => 'Tỷ lệ hoàn tiền là bao nhiêu?',
                    'a' => 'Tỷ lệ hoàn phụ thuộc vào hoa hồng mà sàn trả cho từng sản phẩm. Hệ thống áp dụng 3 mức: hoa hồng dưới 12% thì bạn nhận 50%, từ 12% đến 52% thì bạn nhận 60%, trên 52% thì bạn nhận 70%. Tiền hoàn ước tính được hiển thị ngay khi bạn tạo link.',
                ],
                [
                    'q' => 'Bao lâu nhận được tiền hoàn?',
                    'a' => 'Sau khi đơn hàng được sàn xác nhận hoàn thành (thường 7-30 ngày tùy sàn), tiền hoàn sẽ được cộng vào ví. Bạn có thể rút tiền khi số dư đạt từ 10.000đ.',
                ],
                [
                    'q' => 'Tại sao đơn hàng của tôi bị từ chối hoàn tiền?',
                    'a' => 'Có thể do: đơn hàng bị hủy hoặc trả hàng, bạn không tạo link hoàn tiền trước khi mua, affiliate network không ghi nhận đơn hàng, hoặc đơn hàng vi phạm chính sách của sàn.',
                ],
                [
                    'q' => 'Tôi đã mua hàng nhưng quên tạo link, có được hoàn không?',
                    'a' => 'Không. Nếu bạn đã mua hàng mà chưa tạo link hoàn tiền trước đó, đơn hàng sẽ không được ghi nhận. Hãy nhớ tạo link trước mỗi lần mua sắm.',
                ],
                [
                    'q' => 'Giá sản phẩm có thay đổi không?',
                    'a' => 'Không. Bạn vẫn mua hàng với giá gốc trên sàn. Hoàn Tiền Aff chỉ tạo link giúp sàn ghi nhận đơn hàng qua affiliate, từ đó bạn nhận được tiền hoàn.',
                ],
                [
                    'q' => 'Tối thiểu bao nhiêu tiền mới rút được?',
                    'a' => 'Số tiền rút tối thiểu là 10.000đ.',
                ],
                [
                    'q' => 'Bao lâu xử lý yêu cầu rút tiền?',
                    'a' => 'Yêu cầu rút tiền sẽ được Admin xem xét và xử lý. Số dư ví chỉ giảm khi yêu cầu được thanh toán thành công.',
                ],
                [
                    'q' => 'Rút tiền về ngân hàng nào được?',
                    'a' => 'Bạn có thể rút tiền về bất kỳ tài khoản ngân hàng nào tại Việt Nam. Cần cung cấp tên chủ tài khoản, số tài khoản và tên ngân hàng trong trang Profile.',
                ],
                [
                    'q' => 'Có giới hạn số lần rút tiền không?',
                    'a' => 'Bạn chỉ có thể có tối đa 1 yêu cầu rút tiền đang chờ xử lý cùng lúc. Yêu cầu mới chỉ được tạo khi yêu cầu trước đó đã hoàn thành hoặc bị từ chối.',
                ],
                [
                    'q' => 'Cần khai báo thông tin gì để rút tiền?',
                    'a' => 'Bạn cần điền đầy đủ: tên chủ tài khoản, số tài khoản và tên ngân hàng trong trang Profile. Nếu thiếu, hệ thống sẽ yêu cầu bạn cập nhật trước khi rút tiền.',
                ],
                [
                    'q' => 'Theo dõi đơn hàng ở đâu?',
                    'a' => 'Tại trang Đơn hàng trên Dashboard. Bạn có thể tìm kiếm theo mã đơn, tên shop hoặc tên sản phẩm, và lọc theo sàn hoặc trạng thái.',
                ],
                [
                    'q' => 'Đơn hàng bao lâu được xác nhận?',
                    'a' => 'Tùy sàn: Shopee 7-14 ngày sau giao hàng thành công, Lazada 14-30 ngày, TikTok Shop 7-21 ngày. Các sàn khác theo chính sách riêng.',
                ],
                [
                    'q' => 'Tại sao đơn hàng bị mất khỏi danh sách?',
                    'a' => 'Đơn hàng có thể bị loại khỏi danh sách vì bị hủy, trả hàng, hoặc affiliate network không ghi nhận đơn. Kiểm tra cột trạng thái để biết chi tiết.',
                ],
                [
                    'q' => 'Có dùng trên điện thoại được không?',
                    'a' => 'Có. Hoàn Tiền Aff hoạt động trên trình duyệt điện thoại (Safari, Chrome) bình thường.',
                ],
                [
                    'q' => 'Có nên mua hàng qua Facebook không?',
                    'a' => 'Không nên. Nếu bạn mua hàng qua ứng dụng Facebook, cookie affiliate sẽ không hoạt động và đơn hàng không được ghi nhận. Hãy mở link trên trình duyệt thay vì trong app.',
                ],
                [
                    'q' => 'Có nên mua hàng qua Zalo không?',
                    'a' => 'Không nên. Nếu bạn mua hàng qua ứng dụng Zalo, cookie affiliate sẽ không hoạt động và đơn hàng không được ghi nhận. Hãy mở link trên trình duyệt thay vì trong app.',
                ],
                [
                    'q' => 'Email liên hệ là gì?',
                    'a' => 'Gửi email đến tintuctonghop101@gmail.com. Chúng tôi sẽ phản hồi trong thời gian sớm nhất.',
                ],
                [
                    'q' => 'Liên hệ Zalo ở đâu?',
                    'a' => 'Zalo số 0908 505 990. Bạn có thể nhắn tin trực tiếp để được hỗ trợ nhanh.',
                ],
                [
                    'q' => 'Giờ làm việc hỗ trợ?',
                    'a' => 'Thời gian hỗ trợ: 8:00 - 22:00, Thứ Hai đến Thứ Bảy. Ngoài khung giờ này, vui lòng gửi email và chúng tôi sẽ phản hồi vào ngày làm việc tiếp theo.',
                ],
            ];
            @endphp

            <div class="space-y-4">
                @foreach ($faqs as $faq)
                <div class="bg-white rounded-xl border border-gray-100 shadow-sm">
                    <div class="px-5 py-4">
                        <h3 class="font-semibold text-gray-900 text-[15px] mb-2">{{ $faq['q'] }}</h3>
                        <p class="text-gray-600 text-[15px] leading-relaxed">{{ $faq['a'] }}</p>
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
