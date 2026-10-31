<div class="space-y-2" data-testid="dashboard-guide">
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-700">📘 Hướng dẫn sử dụng</h3>
        <a href="{{ route('guide.index') }}" class="text-xs text-emerald-600 hover:underline">Xem tất cả</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 max-[390px]:px-3 px-4 py-4 space-y-4">
        <p class="text-sm font-semibold text-gray-800">4 bước đặt hàng – nhận hoàn tiền</p>
        <ol class="space-y-3">
            @foreach ([
                ['1', 'Đổi link sản phẩm', 'Dán link sản phẩm vào ô phía trên và bấm Tạo Link.'],
                ['2', 'Mua hàng qua link đã đổi', 'Bấm link mới để mở sàn rồi mua như bình thường.'],
                ['3', 'Kiểm tra đơn hàng', 'Đơn được ghi nhận sau khoảng 8–9 giờ, xem ở mục Đơn & Ví.'],
                ['4', 'Cập nhật STK và rút tiền', 'Hoàn tất đơn để nhận hoa hồng, sau đó rút về tài khoản ngân hàng.'],
            ] as [$n, $title, $desc])
                <li class="flex gap-3">
                    <span class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-100 text-emerald-700 text-sm font-bold">{{ $n }}</span>
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-gray-800">{{ $title }}</div>
                        <div class="text-xs text-gray-500 leading-relaxed">{{ $desc }}</div>
                    </div>
                </li>
            @endforeach
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
