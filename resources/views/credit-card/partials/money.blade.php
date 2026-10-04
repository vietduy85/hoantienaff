{{--
    Định dạng tiền VND — nguồn DUY NHẤT cho view Module Thẻ tín dụng.

    Xem `App\View\Components\CreditCard\Money`: số 0 trước dấu phân cách, phân
    cách nghìn bằng `.`, không phần thập phân. Kèm hậu tố "đ" mặc định vì mọi
    mốc tiền trong module đều là VND — nhờ đó không chỗ nào tự thêm "đ" riêng và
    tự quên mất ở ô khác.

    ---------------------------------------------------------------------------
    "đ" PHẢI KẾ THỪA, KHÔNG TỰ CÓ STYLE
    ---------------------------------------------------------------------------
    Trước đây hậu tố gắn cứng `text-base font-semibold`, nên nó KHÔNG BAO GIỜ
    khớp số tiền đứng cạnh:
      - ở dòng quota (`text-xs` = 12px) chữ "đ" nhảy lên 16px ⇒ to hơn hẳn số.
      - ở ô số lớn (`text-2xl` = 24px) lại nhỏ hơn số.
      - `font-semibold` còn đè lên `font-bold` của chỗ gọi, nên trong cùng một
        số tiền thì chữ số đậm còn chữ "đ" nhạt — đúng cái rõ nhất kiểu chữ vỡ
        trên dòng "Có thể chi thêm ~345.000 đ".

    Bỏ hết class trên hậu tố: "đ" kế thừa đúng cỡ chữ và độ đậm của số tiền nó
    đứng cạnh, nên mọi mốc tiền trong module tự khớp nhau mà không chỗ gọi nào
    phải tự chỉnh.

    ---------------------------------------------------------------------------
    "đ" KHÔNG ĐƯỢC RƠI XUỐNG DÒNG RIÊNG
    ---------------------------------------------------------------------------
    Khoảng trắng giữa số và "đ" là `&nbsp;` (U+00A0) chứ không phải space thường:
    đây là CHỮ duy nhất có thể ngắt dòng trong một số tiền, và trên mobile nó
    rớt xuống dòng mới đúng lúc khung hẹp nhất — "345.000" / "đ". Dùng `&nbsp;`
    thì cả cụm tiền là một đơn vị, không đứt.

    Vì hậu tố nằm sẵn ở đây, chỗ gọi TUYỆT ĐỐI không nối thêm "đ" nữa, nếu không
    ra "345.000 đ đ".
--}}
<span>{{ number_format((float) $value, 0, ',', '.') }}&nbsp;<span>đ</span></span>