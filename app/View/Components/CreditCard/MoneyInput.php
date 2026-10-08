<?php

namespace App\View\Components\CreditCard;

use App\Support\CreditCard\CreditCardMoneyFormatter;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Ô NHẬP SỐ TIỀN đơn vị hiển thị theo user — thay thế cho `<input type="number">`.
 *
 * Trước đây mỗi màn tự viết ô `type="number"` bên cạnh hậu tố "đ" tự gõ, nên
 * người đặt "Nghìn đồng" vẫn bị bắt nhập VND và dấu phẩy thập phân không gõ
 * được (type=number). Component này là MỘT nơi duy nhất cho mọi ô tiền:
 *
 *   - đơn vị hiển thị (`đ` | `nghìn`) theo `money_unit` của user — `x-credit-card.money`;
 *   - state/payload luôn VND: `ccMoneyParseInput()` đổi chuỗi hiển thị về VND
 *     ngay tại ô nhập, nên backend KHÔNG đổi gì;
 *   - ô là `type="text" inputmode="decimal"` để dấu phẩy gõ được (vi-VN), và
 *     placeholder tự sinh theo đơn vị từ `example` (`Ví dụ: 50.000.000` /
 *     `Ví dụ: 50.000`) thay vì gắn cứng "Ví dụ: 50000000";
 *   - pattern chống mất con trỏ: giá trị đang gõ (`ccRaw`) giữ nguyên cho tới
 *     khi blur, nguồn thật là `expr` (biểu thức VND) đã gắn `:value`/
 *     `@input`/`@blur` — vì vậy prop nhận tên biến chứ KHÔNG nhận `x-model`.
 *
 * ---------------------------------------------------------------
 * KHÔNG truyền `:disabled`/`:id` (dạng `:`-prefixed như Alpine) vào INLINE:
 * Blade của component biên dịch `:tên` thành PHIÊU PHP (biểu thức chạy phía
 * server), không phải directive Alpine — `:disabled="viewMode"` sẽ fail khi
 * `viewMode` không tồn tại trong PHP. Thay vậy dùng prop *Biểu thức*:
 *
 *   <x-credit-card.money-input expr="tier.min_total_spend"
 *       id-expr="`cc-t${ti}-min`"
 *       disabled-expr="{{ $p }}viewMode" ... />
 *
 * Blade chỉ đánh giá `{{ }}` trong HTML attribute `"..."` thường, và component
 * render `x-bind:id`/`x-bind:disabled` để Alpine tự binding.
 *
 * VÍ DỤ
 *   <x-credit-card.money-input expr="form.credit_limit" example="50000000"
 *       id="cc-credit-limit" class="w-full h-12 ..." />
 *
 * @property  string          $expr          Biểu thức Alpine giữ giá trị VND, ví
 *                                           dụ `form.credit_limit` hoặc `tier.min_total_spend`.
 * @property  mixed           $example       Số VND mẫu để dựng placeholder theo đơn vị.
 * @property  ?string         $placeholder   Thay placeholder (ưu tiên hơn `example`).
 * @property  ?string         $idExpr        Biểu thức Alpine cho `x-bind:id`
 *                                           (id động trong x-for, vd `` `cc-t${ti}-min` ``).
 * @property  ?string         $disabledExpr  Biểu thức Alpine cho `x-bind:disabled`.
 */
class MoneyInput extends Component
{
    public function __construct(
        public string $expr,
        public mixed $example = null,
        public ?string $placeholder = null,
        public ?string $idExpr = null,
        public ?string $disabledExpr = null,
    ) {}

    public function render(): View
    {
        $userId = Auth::id();

        return view('credit-card.partials.money-input', [
            'userId' => $userId !== null ? (int) $userId : null,
            'suffix' => CreditCardMoneyFormatter::suffix($userId),
        ]);
    }
}