<?php

namespace App\View\Components\CreditCard;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * MỘT chỗ duy nhất định dạng tiền cho toàn bộ view của Module Thẻ tín dụng.
 *
 * Trước đây mỗi view tự `number_format(...)` với tham số riêng nên "hạn mức",
 * "tổng hạn mức", "tổng chi tiêu" và "cashback" dễ lệch nhau. Component này gom
 * về một quy tắc: tiền VND, phân cách nghìn bằng `.`, không có phần thập phân
 * thừa (mọi mốc hạn mức/trần trong module đều tròn đồng).
 *
 * CHỈ định dạng để hiển thị — không dùng để tính toán. Mọi phép cộng/trừ vẫn
 * thực hiện ở tầng SQL/service bằng `decimal`, không bao giờ qua đây.
 *
 * Dùng: `<x-credit-card.money :value="$totalLimit" />`
 */
class Money extends Component
{
    /**
     * @param  mixed  $value  Số tiền (string|int|float|null). `null`/rỗng ⇒ hiển thị 0.
     */
    public function __construct(
        public mixed $value = 0,
    ) {}

    public function render(): View
    {
        return view('credit-card.partials.money');
    }
}
