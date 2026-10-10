{{--
    Ô tìm kiếm thẻ — logic dùng chung cho Tổng quan, Quản lý thẻ và Sao kê.

    Nguồn DUY NHẤT phía client cho chuẩn `data-card-search` mà server in sẵn trên
    từng dòng thẻ: bỏ dấu, `đ`→`d`, chữ thường, gọn khoảng trắng. Ba màn phải khớp
    TỪNG CHỮ nếu không người dùng sẽ có hai cách tìm thẻ khác nhau trên hai trang.

    ---------------------------------------------------------------------------
    KHÔNG GỌI MẠNG, KHÔNG GHI
    ---------------------------------------------------------------------------
    Lọc là việc của trình duyệt trên danh sách server đã sắp: rỗng ⇒ hiện đủ, gõ ⇒
    ẩn dòng không khớp bằng `x-show="cardMatches($el)"`. Không có endpoint, không có
    query string, không đụng vào thứ tự sort và không tạo round-trip theo từng ký tự.

    `ccCardSearchMatchCount(component, testId)` đếm số dòng đang khớp để hiện
    empty state "Không tìm thấy thẻ phù hợp". Mỗi màn truyền TESTID dòng thẻ của
    mình (`card-row` ở Tổng quan / Quản lý thẻ, `statement-row` ở Sao kê) và phải
    đặt `x-ref="cardList"` trên container chứa các dòng đó.
--}}
@once
    <script>
        /**
         * Chuẩn hoá chuỗi để TÌM KIẾM: bỏ dấu, về chữ thường, gọn khoảng trắng.
         *
         * NFD tách dấu thanh khỏi nguyên âm rồi xoá khối combining marks; `đ`
         * (U+0111) không tách được nên đổi tay, kể cả `Đ`. Nhờ vậy gõ "the vp"
         * vẫn khớp "Thẻ VP" và gõ "ngan hang" khớp "Ngân hàng".
         */
        function ccNormalizeSearch(value) {
            return String(value ?? '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/đ/g, 'd')
                .replace(/Đ/g, 'D')
                .toLowerCase()
                .trim();
        }

        /**
         * Số dòng thẻ đang khớp từ khoá của một component — để hiện empty state.
         *
         * Đọc qua `$refs.cardList` (mỗi màn đặt `x-ref="cardList"` trên container
         * chứa các dòng thẻ) rồi lọc đúng `cardMatches(el)` mà `x-show` đang dùng,
         * nên con số này không bao giờ lệch với số dòng đang hiển thị.
         *
         * @param {object} component Alpine component đang sở hữu ô tìm kiếm.
         * @param {string} testId   testid của một dòng thẻ, ví dụ `card-row`.
         * @return {number}
         */
        function ccCardSearchMatchCount(component, testId) {
            const list = component.$refs?.cardList ?? null;

            if (list === null) return 0;

            const selector = '[data-testid="' + testId + '"]';

            return Array.from(list.querySelectorAll(selector))
                .filter((row) => component.cardMatches(row))
                .length;
        }
    </script>
@endonce