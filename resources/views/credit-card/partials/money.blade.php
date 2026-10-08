{{--
    Định dạng số tiền theo đơn vị của USER ĐANG THAO TÁC — nguồn DUY NHẤT cho
    view Module Thẻ tín dụng (bản server).

    Xem `App\View\Components\CreditCard\Money` + `App\Support\CreditCard\
    CreditCardMoneyFormatter`: số 0 trước dấu phân cách, phân cách nghìn bằng `.`,
    không phần thập phân. Đơn vị (`đ` | `nghìn`) do `money_unit` của user quyết
    định — DB LUÔN LƯU VND, đây chỉ là CÁCH ĐỌC. Nhờ đó không chỗ nào tự thêm
    "đ" riêng và tự quên ở ô khác.

    ---------------------------------------------------------------------------
    HẬU TỐ PHẢI KẾ THỪA, KHÔNG TỰ CÓ STYLE
    ---------------------------------------------------------------------------
    Trước đây hậu tố gắn cứng `text-base font-semibold`, nên nó KHÔNG BAO GIỜ
    khớp số tiền đứng cạnh:
      - ở dòng quota (`text-xs` = 12px) chữ "đ" nhảy lên 16px ⇒ to hơn hẳn số.
      - ở ô số lớn (`text-2xl` = 24px) lại nhỏ hơn số.
      - `font-semibold` còn đè lên `font-bold` của chỗ gọi.

    Bỏ hết class trên hậu tố: "đ"/"nghìn" kế thừa đúng cỡ chữ và độ đậm của số
    tiền nó đứng cạnh, nên mọi mốc tiền trong module tự khớp nhau.

    ---------------------------------------------------------------------------
    HẬU TỐ KHÔNG ĐƯỢC RƠI XUỐNG DÒNG RIÊNG
    ---------------------------------------------------------------------------
    Khoảng trắng giữa số và hậu tố là `&nbsp;` (U+00A0) chứ không phải space thường:
    đây là CHỮ duy nhất có thể ngắt dòng trong một số tiền, và trên mobile nó
    rớt xuống dòng mới đúng lúc khung hẹp nhất — "345.000" / "đ". Dùng `&nbsp;`
    thì cả cụm tiền là một đơn vị, không đứt.

    Vì hậu tố nằm sẵn ở đây, chỗ gọi TUYỆT ĐỐI không nối thêm "đ"/"nghìn" nữa,
    nếu không ra "345.000 đ đ".
--}}
<span>{{ \App\Support\CreditCard\CreditCardMoneyFormatter::number($value, auth()->id() ?? null) }}&nbsp;<span>{{ \App\Support\CreditCard\CreditCardMoneyFormatter::suffix(auth()->id() ?? null) }}</span></span>