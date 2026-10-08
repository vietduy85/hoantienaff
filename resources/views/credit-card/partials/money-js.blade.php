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

    ---------------------------------------------------------------------------
    `window.ccMoneySymbol` — KÝ TỰ ĐẠI DIỆN (server cắt vào, ĐÃ resolve)
    ---------------------------------------------------------------------------
    Ký tự người dùng thấy sau con số ("đ", "nghìn", "VND", "k"…), resolve theo
    `money_unit_symbol` + default của đơn vị ở server — JS không tự đoán. Có thể
    là chuỗi RỖNG khi người dùng chủ động bỏ suffix: khi đó mọi chỗ nối hậu tố
    phải trả nguyên văn, KHÔNG thêm NBSP thừa ("2.000.000" chứ không
    "2.000.000 " hay "2.000.000&nbsp;").

    JS phải khớp `CreditCardMoneyFormatter` (PHP) tới từng chữ: VND không phần
    thập phân, nghìn làm tròn XUỐNG về số nguyên — `4237000` ⇒ `4.237`,
    `456999` ⇒ `456` (floor, không làm tròn lên).

    Lưu ý: CHỈ để hiển thị. Mọi phép tính tiền vẫn nằm ở PHP/SQL.
    --}}
@once
<script>
    window.ccMoneyUnit = @json(\App\Support\CreditCard\CreditCardMoneyFormatter::unit(auth()->id() ?? null));
    window.ccMoneySymbol = @json(\App\Support\CreditCard\CreditCardMoneyFormatter::suffix(auth()->id() ?? null));

    /**
     * Hậu tố đơn vị của user — CHUỖI RỖNG nếu người dùng đã bỏ ký tự.
     *
     * `ccMoneySymbol` luôn do server resolve (kể cả default), nên nhánh fallback
     * phía dưới chỉ dành cho trang hiếm hoi chưa cắt symbol vào — giữ cho một
     * trang không bao giờ in ra `undefined`.
     */
    // eslint-disable-next-line no-unused-vars
    function ccMoneySuffix() {
        if (window.ccMoneySymbol !== null && window.ccMoneySymbol !== undefined) {
            return window.ccMoneySymbol;
        }

        return window.ccMoneyUnit === 'THOUSAND_VND' ? 'nghìn' : 'đ';
    }

    /**
     * Nối một chuỗi với hậu tố đơn vị — NGUỒN DUY NHẤT nối suffix phía client.
     *
     *   suffix rỗng  ⇒ trả nguyên văn (không NBSP, không space thừa)
     *   suffix khác  ⇒ `chuỗi` + U+00A0 + suffix (nbsp không ngắt dòng)
     *
     * Mọi chỗ trước đây tự viết `` `${ccMoney(x)}\u00A0${ccMoneySuffix()}` ``
     * phải đi qua helper này để symbol rỗng không để lại khoảng trắng treo
     * ("5.000 /kỳ" hay "2.000.000&nbsp;").
     */
    // eslint-disable-next-line no-unused-vars
    function ccMoneySuffixJoin(text) {
        const suffix = ccMoneySuffix();

        return suffix === '' ? String(text) : `${text}\u00A0${suffix}`;
    }

    /**
     * Số tiền theo đơn vị của user (không hậu tố): `4.237.000` hoặc `4.237`.
     *
     * Ở đơn vị nghìn: làm tròn VND về đồng nguyên TRƯỚC khi chia (half away
     * from zero, khớp `number_format(..., 0, ...)` của PHP), rồi FLOOR xuống
     * nhóm 1000 — `456.999` ⇒ `456`, `499.999` ⇒ `499`, không bao giờ làm tròn
     * lên. Chia trần rồi mới cắt float ở đúng điểm biên (`456999/1000` qua
     * IEEE-754 là một cờ bạc) nên floor luôn chạy trên số đồng đã nguyên.
     */
    // eslint-disable-next-line no-unused-vars
    function ccMoney(value) {
        const amount = Number(value ?? 0);

        if (window.ccMoneyUnit === 'THOUSAND_VND') {
            const rounded = Math.round(amount >= 0 ? amount : -amount) * (amount >= 0 ? 1 : -1);

            return Math.floor(rounded / 1000).toLocaleString('vi-VN', { maximumFractionDigits: 0 });
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
     * Vì vậy hậu tố nối qua `ccMoneySuffixJoin()` (NBSP U+00A0, khớp đúng partial
     * phía server; suffix rỗng ⇒ không nối gì). Hậu tố lấy từ `ccMoneySuffix()`
     * chứ không viết cứng "đ": đơn vị nghìn hiển thị "nghìn", và người dùng có
     * thể đã đổi sang ký tự khác ("VND", "k") hoặc bỏ hẳn ký tự.
     */
    // eslint-disable-next-line no-unused-vars
    function ccMoneyVnd(value) {
        return ccMoneySuffixJoin(ccMoney(value));
    }

    /**
     * Chuỗi ĐIỀN VÀO ô nhập tiền trước khi user sửa — cùng con số người dùng
     * nhìn thấy trên màn hình.
     *
     * NHẬN VND, trả chuỗi ĐƠN VỊ HIỂN THỊ — nghịch đảo của
     * `ccMoneyParseInput()`. `expr` của `x-credit-card.money-input` luôn là VND,
     * nên phải đổi VND → đơn vị TRƯỚC khi `toLocaleString` — nếu chỉ format số
     * VND thì prefill ra "3.900.000" thay vì "3.900".
     *
     * Đơn vị nghìn floor về số nguyên như `ccMoney()` và như `input()` của PHP
     * (`103.400` ⇒ `103`, `4.237.500` ⇒ `4.237`): prefill không bao giờ đưa
     * lại số lẻ mà màn hình đã mất — ô sửa kéo theo chữ số ngoài sẽ vô tình
     * đổi tiền. VND giữ tối đa 2 chữ số thập phân (`0,01`). `''` khi chưa có
     * giá trị để ô hiện trống.
     */
    // eslint-disable-next-line no-unused-vars
    function ccMoneyToDisplay(value) {
        if (value === '' || value === null || value === undefined || Number.isNaN(value)) return '';

        const amount = Number(value);

        if (window.ccMoneyUnit === 'THOUSAND_VND') {
            const rounded = Math.round(amount >= 0 ? amount : -amount) * (amount >= 0 ? 1 : -1);

            return Math.floor(rounded / 1000).toLocaleString('vi-VN', { maximumFractionDigits: 0 });
        }

        return amount.toLocaleString('vi-VN', { maximumFractionDigits: 2 });
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

                // Không có dấu chấm ⇒ TOÀN BỘ là phần nguyên. Đừng dùng
                // `slice(0, dot)` khi `dot === -1` — nó cắt mất chữ số cuối và
                // `slice(dot + 1)` trả cả chuỗi, biến "3900" thành 390 + 3900,
                // lập tức cho ra 390.390 thay vì 3.900.000 đ.
                if (dot === -1) {
                    integer = text;
                    decimal = '';
                } else {
                    integer = text.slice(0, dot);
                    decimal = text.slice(dot + 1);
                }
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