<x-layouts.public :pageTitle="$pageTitle" :pageDescription="$pageDescription" :canonical="$canonical">
    <section class="py-12 sm:py-16">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Chính sách hoàn tiền</h1>
            <p class="text-gray-500 mb-10">Hiểu rõ cách thức nhận tiền hoàn tại Hoàn Tiền Aff.</p>

            <div class="space-y-8">

                {{-- What is --}}
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <h2 class="text-lg font-bold text-gray-900 mb-3">Hoàn tiền là gì?</h2>
                    <p class="text-gray-600 text-[15px] leading-relaxed">
                        Hoàn tiền (cashback) là hình thức bạn nhận lại một phần tiền từ hoa hồng affiliate mà hệ thống nhận được từ các sàn thương mại điện tử. Khi bạn mua sắm qua link hoàn tiền của Hoàn Tiền Aff, sàn sẽ trả hoa hồng cho hệ thống, và một phần hoa hồng đó được chuyển lại cho bạn dưới dạng tiền mặt.
                    </p>
                </div>

                {{-- Important note --}}
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6">
                    <div class="flex gap-3">
                        <span class="text-2xl shrink-0">⚠️</span>
                        <div>
                            <h2 class="font-bold text-amber-900 mb-2">Điều quan trọng cần biết</h2>
                            <p class="text-sm text-amber-800 leading-relaxed">
                                <strong>Hoàn Tiền Aff KHÔNG bán hàng hóa.</strong> Website chỉ cung cấp dịch vụ tạo link affiliate để bạn nhận tiền hoàn khi mua sắm trực tiếp trên các sàn thương mại điện tử. Bạn mua hàng từ sàn, không phải từ Hoàn Tiền Aff.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Platforms --}}
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <h2 class="text-lg font-bold text-gray-900 mb-4">Tỷ lệ hoàn tiền theo nền tảng</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100">
                                    <th class="text-left py-2 font-semibold text-gray-900">Nền tảng</th>
                                    <th class="text-left py-2 font-semibold text-gray-900">Tỷ lệ hoàn</th>
                                    <th class="text-left py-2 font-semibold text-gray-900">Lưu ý</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600">
                                <tr class="border-b border-gray-50">
                                    <td class="py-2.5">🟠 Shopee</td>
                                    <td class="py-2.5">50% - 70% hoa hồng</td>
                                    <td class="py-2.5">Tùy danh mục sản phẩm</td>
                                </tr>
                                <tr class="border-b border-gray-50">
                                    <td class="py-2.5">🔵 Lazada</td>
                                    <td class="py-2.5">Theo chiến dịch</td>
                                    <td class="py-2.5">Có thể thay đổi</td>
                                </tr>
                                <tr class="border-b border-gray-50">
                                    <td class="py-2.5">🎵 TikTok Shop</td>
                                    <td class="py-2.5">Theo chiến dịch</td>
                                    <td class="py-2.5">Có thể thay đổi</td>
                                </tr>
                                <tr class="border-b border-gray-50">
                                    <td class="py-2.5">🏨 Agoda</td>
                                    <td class="py-2.5">Theo chiến dịch</td>
                                    <td class="py-2.5">Đang mở rộng</td>
                                </tr>
                                <tr class="border-b border-gray-50">
                                    <td class="py-2.5">🛏️ Booking.com</td>
                                    <td class="py-2.5">Theo chiến dịch</td>
                                    <td class="py-2.5">Đang mở rộng</td>
                                </tr>
                                <tr>
                                    <td class="py-2.5">✈️ Traveloka</td>
                                    <td class="py-2.5">Theo chiến dịch</td>
                                    <td class="py-2.5">Đang mở rộng</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- When NOT to receive --}}
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <h2 class="text-lg font-bold text-gray-900 mb-3">Khi nào KHÔNG được hoàn tiền?</h2>
                    <ul class="space-y-2 text-gray-600 text-[15px]">
                        <li class="flex gap-2">
                            <span class="text-red-500 shrink-0">✗</span>
                            <span>Đơn hàng bị hủy bởi bạn hoặc người bán</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-red-500 shrink-0">✗</span>
                            <span>Đơn hàng trả hàng / hoàn trả</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-red-500 shrink-0">✗</span>
                            <span>Không tạo link hoàn tiền trước khi mua</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-red-500 shrink-0">✗</span>
                            <span>Affiliate network từ chối ghi nhận đơn hàng</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-red-500 shrink-0">✗</span>
                            <span>Đơn hàng vi phạm chính sách của sàn</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="text-red-500 shrink-0">✗</span>
                            <span>Đơn hàng mua bằng mã giảm giá của Hoàn Tiền Aff (nếu có)</span>
                        </li>
                    </ul>
                </div>

                {{-- Adjustment --}}
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <h2 class="text-lg font-bold text-gray-900 mb-3">Quyền điều chỉnh</h2>
                    <p class="text-gray-600 text-[15px] leading-relaxed">
                        Hoàn Tiền Aff có quyền điều chỉnh số tiền hoàn tiền trong trường hợp affiliate network (Shopee, Lazada, TikTok, Agoda, Booking.com, Traveloka) từ chối ghi nhận đơn hàng hoặc điều chỉnh hoa hồng. Mọi điều chỉnh sẽ được ghi nhận rõ ràng trong lịch sử giao dịch của bạn.
                    </p>
                </div>

                {{-- How to receive --}}
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <h2 class="text-lg font-bold text-gray-900 mb-3">Quy trình nhận tiền hoàn</h2>
                    <ol class="space-y-3 text-gray-600 text-[15px]">
                        <li class="flex gap-3">
                            <span class="bg-emerald-100 text-emerald-700 font-bold text-sm w-6 h-6 rounded-full flex items-center justify-center shrink-0">1</span>
                            <span>Tạo link hoàn tiền trên Hoàn Tiền Aff trước khi mua sắm.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="bg-emerald-100 text-emerald-700 font-bold text-sm w-6 h-6 rounded-full flex items-center justify-center shrink-0">2</span>
                            <span>Nhấp vào link và mua sắm trên sàn thương mại điện tử.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="bg-emerald-100 text-emerald-700 font-bold text-sm w-6 h-6 rounded-full flex items-center justify-center shrink-0">3</span>
                            <span>Đơn hàng được sàn ghi nhận và xác nhận hoàn thành.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="bg-emerald-100 text-emerald-700 font-bold text-sm w-6 h-6 rounded-full flex items-center justify-center shrink-0">4</span>
                            <span>Hệ thống tự động cộng tiền hoàn vào ví của bạn.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="bg-emerald-100 text-emerald-700 font-bold text-sm w-6 h-6 rounded-full flex items-center justify-center shrink-0">5</span>
                            <span>Rút tiền về tài khoản ngân hàng khi đạt ngưỡng tối thiểu.</span>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
