{{--
    Định dạng tiền/tỷ lệ cho JAVASCRIPT của Module Thẻ tín dụng.

    Nguồn DUY NHẤT cho phía client: `@include` vào `<script>` của mọi view cần
    hiển thị tiền (Tổng quan, Quản lý thẻ…). Nhờ đó "tổng hạn mức" ở Tổng quan và
    "hạn mức" ở form thẻ không thể lệch nhau vì mỗi nơi tự viết
    `toLocaleString` với tham số riêng.

    Phía server dùng bản PHP của cùng quy tắc: `x-credit-card.money`
    (xem `resources/views/credit-card/partials/money.blade.php`).

    ---------------------------------------------------------------------------
    `window.ccMoneyUnit` — ĐƠN VỊ CỦA NGƯỜI ĐANG THAO TÁC (server cắt vào)
    ---------------------------------------------------------------------------
    Đơn vị đọc số (`VND` | `THOUSAND_VND`) là thiết lập của user đang đăng nhập,
    server ghi thẳng vào đây nên mọi hàm dưới đây tự định dạng đúng đơn vị mà
    KHÔNG cần gọi API. Đây CHỈ là cách ĐỌC: DB và mọi phép tính vẫn là VND.

    JS phải khớp `CreditCardMoneyFormatter` (PHP) tới từng chữ: VND không phần
    thập phân, nghìn giữ tối đa 3 chữ số thập phân (= chính xác 1 đồng) và bỏ số
    0 vô nghĩa (`4237000` ⇒ `4.237`, `103400` ⇒ `103,4`).

    Lưu ý: CHỈ để hiển thị. Mọi phép tính tiền vẫn nằm ở PHP/SQL.
--}}
@once
<script>
    window.ccMoneyUnit = @json(\App\Support\CreditCard\CreditCardMoneyFormatter::unit(auth()->id() ?? null));

    /** Hậu tố theo đơn vị đang chọn: "đ" hoặc "nghìn". */
    // eslint-disable-next-line no-unused-vars
    function ccMoneySuffix() {
        return window.ccMoneyUnit === 'THOUSAND_VND' ? 'nghìn' : 'đ';
    }

    /**
     * Số tiền theo đơn vị của user (không hậu tố): `4.237.000` hoặc `4.237`.
     *
     * Ở đơn vị nghìn, nhân tố nghìn làm tròn VND về đồng nguyên TRƯỚC khi chia
     * để khớp PHP (`number_format(..., 0, ...)` làm tròn half-away-from-zero) —
     * chia trần thì `103,499` đọc thành `103,499`, lệch đúng 1 đồng hiển thị.
     */
    // eslint-disable-next-line no-unused-vars
    function ccMoney(value) {
        const amount = Number(value ?? 0);

        if (window.ccMoneyUnit === 'THOUSAND_VND') {
            const rounded = Math.round(amount >= 0 ? amount : -amount) * (amount >= 0 ? 1 : -1);

            return (rounded / 1000).toLocaleString('vi-VN', { maximumFractionDigits: 3 });
        }

        return amount.toLocaleString('vi-VN', { maximumFractionDigits: 0 });
    }

    /** Tỷ lệ giữ tối đa 2 chữ số thập phân, bỏ số 0 vô nghĩa. */
    // eslint-disable-next-line no-unused-vars
    function ccNumber(value) {
        return Number(value ?? 0).toLocaleString('vi-VN', { maximumFractionDigits: 2 });
    }

    /**
     * Số tiền có hậu tố theo đơn vị — bản JS của `x-credit-card.money`.
     *
     * `x-text` ghi đè nội dung phần tử bằng `textContent`, nên bản render sẵn của
     * `x-credit-card.money` bị thay mất. Nếu chỗ gọi tự nối `+ ' đ'` bằng SPACE
     * THƯỜNG thì số và hậu tố lại tách được — đúng lỗi "345.000" / "đ" trên mobile.
     * Vì vậy hậu tố nối bằng `\u00A0` (nbsp) ở đây, khớp đúng partial phía server:
     * hai bên dùng chung một quy tắc, không bên nào có thể lệch. Hậu tố lấy từ
     * `ccMoneySuffix()` chứ không viết cứng "đ": đơn vị nghìn hiển thị "nghìn".
     */
    // eslint-disable-next-line no-unused-vars
    function ccMoneyVnd(value) {
        return `${ccMoney(value)}\u00A0${ccMoneySuffix()}`;
    }

    /**
     * Chuỗi ĐIỀN VÀO ô nhập tiền trước khi user sửa — bảo toàn đủ chữ số.
     *
     * Khác `ccMoney()`: ô nhập là cửa vào VÀ RA của dữ liệu nên phải đọc lại được
     * CHÍNH XÁC giá trị (kể cả lẻ xu) — ngược lại làm tròn khi hiển thị thì lần
     * sửa sau vô tình đổi tiền. VND giữ tối đa 2 chữ số thập phân, nghìn giữ tối
     * đa 5 (0,00001 nghìn = 0,01 đ). `''` khi chưa có giá trị để ô hiện trống.
     */
    // eslint-disable-next-line no-unused-vars
    function ccMoneyToDisplay(value) {
        if (value === '' || value === null || value === undefined || Number.isNaN(value)) return '';

        return Number(value).toLocaleString('vi-VN', {
            maximumFractionDigits: window.ccMoneyUnit === 'THOUSAND_VND' ? 5 : 2,
        });
    }

    /**
     * Đọc chuỗi user vừa gõ trong ô nhập → SỐ VND (hoặc `''` khi ô rỗng).
     *
     * Dấu phẩy là dấu thập phân (vi-VN); dấu chấm khớp nhóm 3 chữ số từ phải là
     * phân cách NGHÌN và bị bỏ (4.237.000 = 4.237.000, không phải 4,237), còn dấu
     * chấm không khớp nhóm 3 là dấu thập phân (4237000.5). Tất cả dùng SỐ NGUYÊN
     * để tránh lệch float khi đổi nghìn → đồng (1 nghìn = 1.000 đồng):
     *
     *   "4.237" (nghìn) ⇒ 4237 × 1000 + 0       = 4.237.000 đ
     *   "103,4" (nghìn) ⇒ 103 × 1000 + 400      = 103.400 đ
     *   "0,1005" (nghìn) ⇒ 0 × 1000 + 100,5     = 100,5 đ
     *
     * @return {number|string}
     */
    // eslint-disable-next-line no-unused-vars
    function ccMoneyParseInput(raw) {
        let text = String(raw ?? '')
            .replace(/[\s\u00A0]/g, '')
            .replace(/[^\d.,-]/g, '');

        if (text === '') return '';
        if (text === '-' || text === '.' || text === ',') return '';

        const sign = text.startsWith('-') ? -1 : 1;
        text = text.replace(/^-/, '');

        let integer = text;
        let decimal = '';

        if (text.includes(',')) {
            const parts = text.split(',');
            integer = parts[0];
            decimal = parts.slice(1).join('').replace(/\./g, '');
        } else {
            // Chấm đơn, khớp nhóm 3 ⇒ phân cách nghìn; không khớp ⇒ thập phân.
            const grouped = /^\d{1,3}(\.\d{3})+$/.test(text);

            if (grouped) {
                integer = text.replace(/\./g, '');
            } else {
                const dot = text.lastIndexOf('.');
                integer = text.slice(0, dot);
                decimal = text.slice(dot + 1);
            }
        }

        integer = integer.replace(/\D/g, '');
        if (integer === '') return '';

        const whole = Number(integer);
        const fraction = Number((decimal || '').padEnd(window.ccMoneyUnit === 'THOUSAND_VND' ? 5 : 2, '0').slice(0, window.ccMoneyUnit === 'THOUSAND_VND' ? 5 : 2) || '0');
        const exponent = window.ccMoneyUnit === 'THOUSAND_VND' ? 100000 : 100;

        return sign * ((whole * exponent) + fraction) / 100;
    }
</script>
@endonce