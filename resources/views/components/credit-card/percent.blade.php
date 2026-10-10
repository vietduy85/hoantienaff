{{--
    Hiển thị TỶ LỆ PHẦN TRĂM — nguồn duy nhất cho view Báo cáo.

    Khác `x-credit-card.money`: KHÔNG kèm hậu tố tiền và KHÔNG phụ thuộc Money
    Unit. `null` (chi tiêu bằng 0) ra `—`; ngược lại `7,14%`.

    Dùng: `<x-credit-card.percent :value="$row['percent']" />`
--}}
@props(['value' => null])
<span>{{ \App\Support\CreditCard\CreditCardPercentFormatter::text($value) }}</span>
