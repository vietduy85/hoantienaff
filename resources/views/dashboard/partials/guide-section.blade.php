{{-- 4 bước đặt hàng. Mỗi bước có hình minh hoạ vẽ bằng HTML (giống giao diện thật, nét trên mọi màn hình),
     vòng đỏ + 👆 khoanh đúng chỗ user cần bấm. Hình chỉ để xem: aria-hidden, không bấm được. --}}
@php
    // Vòng khoanh đỏ bọc quanh phần tử cần bấm.
    $ring = 'absolute -inset-1.5 rounded-xl border-2 border-red-500 pointer-events-none';
    $finger = 'absolute -bottom-3 -right-1 text-lg leading-none motion-safe:animate-bounce pointer-events-none';
    $frame = 'mt-2 bg-gray-50 border border-gray-200 rounded-xl p-3 select-none pointer-events-none';
@endphp
<div class="space-y-2" data-testid="dashboard-guide">
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-700">📘 Hướng dẫn sử dụng</h3>
        <a href="{{ route('guide.index') }}" class="text-xs text-emerald-600 hover:underline">Xem tất cả</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 max-[390px]:px-3 px-4 py-4 space-y-4">
        <p class="text-sm font-semibold text-gray-800">4 bước đặt hàng – nhận hoàn tiền</p>
        <ol class="space-y-5">
            {{-- Bước 1 --}}
            <li class="flex gap-3" data-testid="guide-step">
                <span class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-100 text-emerald-700 text-sm font-bold">1</span>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-gray-800">Đổi link sản phẩm</div>
                    <div class="text-xs text-gray-500 leading-relaxed">Copy link sản phẩm trên sàn, dán vào ô phía trên và bấm <b class="text-gray-700">Tạo Link Ngay</b>.</div>
                    <div class="{{ $frame }}" aria-hidden="true">
                        <div class="h-9 px-3 flex items-center bg-white border-2 border-gray-200 rounded-lg text-xs text-gray-700 truncate">https://s.shopee.vn/AbC123xyz</div>
                        <div class="relative mt-3">
                            <div class="h-8 rounded-lg bg-emerald-500 text-white text-xs font-semibold flex items-center justify-center gap-1">🚀 Tạo Link Ngay</div>
                            <span class="{{ $ring }}"></span>
                            <span class="{{ $finger }}">👆</span>
                        </div>
                    </div>
                </div>
            </li>

            {{-- Bước 2 --}}
            <li class="flex gap-3" data-testid="guide-step">
                <span class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-100 text-emerald-700 text-sm font-bold">2</span>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-gray-800">Mua hàng qua link đã đổi</div>
                    <div class="text-xs text-gray-500 leading-relaxed">
                        Bấm nút <b class="text-gray-700">🛒 Add giỏ / Mua ngay</b>. Sàn mở ra, bạn
                        <b class="text-gray-700">thêm vào giỏ</b> hoặc <b class="text-gray-700">mua ngay</b> rồi thanh toán như bình thường.
                    </div>
                    <div class="{{ $frame }} space-y-3" aria-hidden="true">
                        <div class="flex gap-2">
                            <div class="flex-[0.35] h-8 bg-white text-emerald-700 text-[11px] font-semibold rounded-lg border-2 border-emerald-200 flex items-center justify-center">📋 Sao chép</div>
                            <div class="relative flex-[0.65]">
                                <div class="h-8 bg-emerald-500 text-white text-[11px] font-semibold rounded-lg flex items-center justify-center">🛒 Add giỏ / Mua ngay</div>
                                <span class="{{ $ring }}"></span>
                                <span class="{{ $finger }}">👆</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 text-[11px] text-gray-400 pt-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                            Ứng dụng sàn mở ra, mua như bình thường
                        </div>
                        <div class="relative">
                            <div class="flex h-9 rounded-lg overflow-hidden text-[11px] font-semibold">
                                <div class="flex-1 bg-orange-100 text-orange-700 flex items-center justify-center">Thêm vào giỏ hàng</div>
                                <div class="flex-1 bg-orange-500 text-white flex items-center justify-center">Mua ngay</div>
                            </div>
                            <span class="{{ $ring }}"></span>
                        </div>
                    </div>
                </div>
            </li>

            {{-- Bước 3 --}}
            <li class="flex gap-3" data-testid="guide-step">
                <span class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-100 text-emerald-700 text-sm font-bold">3</span>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-gray-800">Kiểm tra đơn hàng</div>
                    <div class="text-xs text-gray-500 leading-relaxed">
                        Đơn được ghi nhận sau khoảng <b class="text-gray-700">6 giờ</b>
                        (riêng <b class="text-gray-700">Shopee là ngày hôm sau</b>), xem ở mục <b class="text-gray-700">Đơn &amp; Ví</b>.
                    </div>
                    <div class="{{ $frame }} space-y-3" aria-hidden="true">
                        <div class="bg-white rounded-lg border border-gray-100 px-3 py-2 flex items-center justify-between">
                            <div>
                                <div class="text-xs text-gray-800">Shopee</div>
                                <div class="text-[11px] text-gray-400">Shopee · hôm nay · <span class="text-amber-600">Đang chờ xử lý</span></div>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] text-gray-400">Hoa hồng</div>
                                <div class="text-xs font-semibold text-emerald-600">12.500 đ</div>
                            </div>
                        </div>
                        <div class="flex bg-white rounded-lg border border-gray-100 py-1.5 text-[10px] text-gray-500">
                            <div class="flex-1 text-center">🏠<br>Hoàn tiền</div>
                            <div class="flex-1 text-center relative">
                                <span class="text-gray-800 font-semibold">👛<br>Đơn &amp; Ví</span>
                                <span class="{{ $ring }} -inset-x-0.5 -inset-y-1"></span>
                                <span class="{{ $finger }}">👆</span>
                            </div>
                            <div class="flex-1 text-center">💳<br>Thẻ</div>
                            <div class="flex-1 text-center">⋯<br>Thêm</div>
                        </div>
                    </div>
                </div>
            </li>

            {{-- Bước 4 --}}
            <li class="flex gap-3" data-testid="guide-step">
                <span class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-100 text-emerald-700 text-sm font-bold">4</span>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-gray-800">Cập nhật STK và rút tiền</div>
                    <div class="text-xs text-gray-500 leading-relaxed">
                        Đơn hoàn tất thì hoa hồng vào ví. Cập nhật số tài khoản ở <b class="text-gray-700">Đơn &amp; Ví → Tài khoản &amp; STK</b>,
                        rồi bấm <b class="text-gray-700">Rút tiền</b>.
                    </div>
                    <div class="{{ $frame }}" aria-hidden="true">
                        <div class="text-[11px] text-gray-400">Số dư khả dụng</div>
                        <div class="text-base font-bold text-emerald-600 leading-tight">125.000<span class="text-xs">đ</span></div>
                        <div class="relative mt-2.5">
                            <div class="h-8 rounded-lg bg-emerald-500 text-white text-xs font-semibold flex items-center justify-center">Rút tiền</div>
                            <span class="{{ $ring }}"></span>
                            <span class="{{ $finger }}">👆</span>
                        </div>
                    </div>
                </div>
            </li>
        </ol>

        <div class="bg-red-50 border border-red-100 rounded-lg px-3 py-2.5 space-y-1">
            <p class="text-xs font-semibold text-red-700">Lưu ý để không rớt đơn:</p>
            <ul class="text-xs text-red-700/90 leading-relaxed list-disc list-inside space-y-0.5">
                <li>Đổi link xong mua luôn, không bấm link Shopee khác trong quá trình mua.</li>
                <li>Mã giảm giá: lưu mã trước rồi mới đổi link.</li>
                <li>Cùng shop chỉ cần đổi 1 link; khác shop thì mỗi shop 1 link, cho vào giỏ hàng rồi thanh toán.</li>
            </ul>
        </div>

        <a href="{{ route('guide.index') }}" class="block text-center text-sm font-medium text-emerald-600">Xem tất cả hướng dẫn →</a>
    </div>
</div>
