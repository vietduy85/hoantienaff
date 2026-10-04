{{--
    Định dạng tiền/tỷ lệ cho JAVASCRIPT của Module Thẻ tín dụng.

    Nguồn DUY NHẤT cho phía client: `@include` vào `<script>` của mọi view cần
    hiển thị tiền (Tổng quan, Quản lý thẻ…). Nhờ đó "tổng hạn mức" ở Tổng quan và
    "hạn mức" ở form thẻ không thể lệch nhau vì mỗi nơi tự viết
    `toLocaleString` với tham số riêng.

    Phía server dùng bản PHP của cùng quy tắc: `x-credit-card.money`
    (xem `resources/views/credit-card/partials/money.blade.php`).

    Lưu ý: CHỈ để hiển thị. Mọi phép tính tiền vẫn nằm ở PHP/SQL.
--}}
<script>
    // eslint-disable-next-line no-unused-vars
    function ccMoney(value) {
        return Number(value ?? 0).toLocaleString('vi-VN', { maximumFractionDigits: 0 });
    }

    /** Tỷ lệ giữ tối đa 2 chữ số thập phân, bỏ số 0 vô nghĩa. */
    // eslint-disable-next-line no-unused-vars
    function ccNumber(value) {
        return Number(value ?? 0).toLocaleString('vi-VN', { maximumFractionDigits: 2 });
    }

    /**
     * Số tiền có hậu tố "đ" — bản JS của `x-credit-card.money`.
     *
     * `x-text` ghi đè nội dung phần tử bằng `textContent`, nên bản render sẵn của
     * `x-credit-card.money` bị thay mất. Nếu chỗ gọi tự nối `+ ' đ'` bằng SPACE
     * THƯỜNG thì số và "đ" lại tách được — đúng lỗi "345.000" / "đ" trên mobile.
     * Vì vậy hậu tố nối bằng `\u00A0` (nbsp) ở đây, khớp đúng partial phía server:
     * hai bên dùng chung một quy tắc, không bên nào có thể lệch.
     */
    // eslint-disable-next-line no-unused-vars
    function ccMoneyVnd(value) {
        return `${ccMoney(value)}\u00A0đ`;
    }
</script>
