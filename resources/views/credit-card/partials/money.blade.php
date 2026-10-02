{{--
    Định dạng tiền VND — nguồn DUY NHẤT cho view Module Thẻ tín dụng.

    Xem `App\View\Components\CreditCard\Money`: số 0 trước dấu phân cách, phân
    cách nghìn bằng `.`, không phần thập phân. Kèm hậu tố "đ" mặc định vì mọi
    mốc tiền trong module đều là VND — nhờ đó không chỗ nào tự thêm "đ" riêng và
    tự quên mất ở ô khác.
--}}
<span>{{ number_format((float) $value, 0, ',', '.') }}<span class="text-base font-semibold">đ</span></span>
