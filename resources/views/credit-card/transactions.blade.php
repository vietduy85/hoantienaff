{{--
    Lịch sử giao dịch của MỘT thẻ — /thetindung/the/{userCard}/giao-dich

    ---------------------------------------------------------------------------
    PHẠM VI
    ---------------------------------------------------------------------------
    Trang này chỉ ĐỌC và SỬA. Thêm giao dịch nằm ở Tổng quan (form nhập tay ngay
    trên trang) để không phải rời trang tổng quan khi ghi một khoản chi.

    Sửa gọi API `credit-cards.api.transactions.update` — tức
    `UpdateTransactionRequest` + `CreditCardTransactionService::update()`: đổi ngày
    qua kỳ sẽ gắn lại kỳ sao kê và tính lại cashback. Trang KHÔNG tự tính
    cashback; cột "Hoàn tiền" đọc snapshot engine đã ghi.

    ---------------------------------------------------------------------------
    CHỈ SỬA ĐƯỢC 4 Ô
    ---------------------------------------------------------------------------
    Ngày · Số tiền · Danh mục · Ghi chú. KHÔNG cho đổi thẻ (thuộc form khác) và
    KHÔNG cho đổi `statement_period_id` — kỳ do ngày quyết định, không chọn tay.

    Giao dịch trong kỳ ĐÃ CHỐT hiện khoá sửa: `TransactionPolicy` chặn ở server,
    UI khoá để lý do rõ ràng chứ không phải để chặn.
--}}
<x-credit-card.layout
    :title="$card->name ?: 'Lịch sử giao dịch'"
    :subtitle="'Lịch sử giao dịch của thẻ này'"
    active="index">

    @php
        // URL gọi API lấy từ `route()` ở controller, KHÔNG gõ tay trong JS.
        // Mỗi dòng có sẵn URL update của riêng nó nên JS không phải nối chuỗi.
        $historyState = [
            'rows' => $rows,
            // Số dòng tổng (chưa phân trang) — xoá một dòng thì trừ ngay trên
            // header, không cần tải lại trang.
            'total' => (int) $total,
            'update_urls' => collect($rows)->mapWithKeys(fn (array $row): array => [
                $row['id'] => route('credit-cards.api.transactions.update', ['transaction' => $row['id']]),
            ])->all(),
            'delete_urls' => collect($rows)->mapWithKeys(fn (array $row): array => [
                $row['id'] => route('credit-cards.api.transactions.destroy', ['transaction' => $row['id']]),
            ])->all(),
            'categories' => $categories,
        ];

        // Dữ liệu cho ô chọn TÌM KIẾM ĐƯỢC của form sửa (chỉ danh mục — KHÔNG có
        // chọn thẻ, xem "CHỈ SỬA ĐƯỢC 4 Ô").
        $categoryOptions = collect($categories)->map(fn (array $category): array => [
            'id' => $category['id'],
            'label' => $category['name'],
            'sublabel' => $category['scope'] === 'user' ? 'Của tôi' : 'Hệ thống',
        ])->values()->all();

        // Query string giữ nguyên khi đổi trang.
        $queryFor = fn (int $target): string => request()->fullUrlWithQuery(['page' => $target]);
    @endphp

    <div class="space-y-4">
        {{-- Đường dẫn quay lại: luôn có nút "quay lại" trên điện thoại, nơi
             trình duyệt không hiện nút back hệ thống một cách đáng tin cậy. --}}
        <a href="{{ route('credit-cards.index') }}"
           class="inline-flex items-center gap-1.5 h-10 px-3 -ml-3 rounded-xl text-sm font-medium text-gray-600 hover:bg-white hover:text-gray-800 transition-colors">
            <span aria-hidden="true">&larr;</span> Về Tổng quan
        </a>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-3">
            <div class="flex items-start justify-between gap-3 min-w-0">
                <div class="min-w-0">
                    {{-- Tên thẻ do user đặt; chỉ thêm 4 số cuối để phân biệt,
                         không hiện tên ngân hàng (đã xem ở Quản lý thẻ) và tuyệt
                         đối không hiện số thẻ đầy đủ. --}}
                    <h3 class="font-bold text-gray-800 break-words">
                        {{ $card->name ?: 'Thẻ tín dụng' }}
                        @if ($card->card_number_last4)
                            <span class="font-normal text-gray-400">· •••• {{ $card->card_number_last4 }}</span>
                        @endif
                    </h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        {{-- Đếm gộp trong MỘT span: server render "{{ $total }} giao dịch"
                     (text liền mạch cho test/SEO), Alpine ghi đè khi xoá dòng. --}}
                        <span x-text="total + ' giao dịch'">{{ $total }} giao dịch</span> · Ngày chốt bảng kê: hàng {{ $card->statement_day }}
                    </p>
                </div>
                <a href="{{ route('credit-cards.manage') }}"
                   class="shrink-0 inline-flex items-center h-10 px-3 bg-gray-50 hover:bg-gray-100 text-gray-600 text-sm font-semibold rounded-xl transition-colors">
                    Sửa thẻ
                </a>
            </div>

            {{-- Bộ lọc. GET thường ⇒ nút "Lọc" chạy mà không cần JS, và URL có thể
                 chia sẻ/bookmark lại. --}}
            <form method="GET" action="{{ route('credit-cards.transactions', ['userCard' => $card->id]) }}"
                  class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-2 pt-1">
                <select name="period_id" aria-label="Lọc theo kỳ sao kê"
                        class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">Tất cả kỳ</option>
                    @foreach ($periods as $period)
                        <option value="{{ $period->id }}" @selected((int) ($filters['period_id'] ?? 0) === (int) $period->id)>
                            {{ $period->period_start?->format('d/m') }}–{{ $period->period_end?->format('d/m/Y') }}
                            @if ($period->isFinalized()) (đã chốt) @endif
                        </option>
                    @endforeach
                </select>

                <select name="category_id" aria-label="Lọc theo danh mục"
                        class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">Tất cả danh mục</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category['id'] }}" @selected((int) ($filters['category_id'] ?? 0) === $category['id'])>
                            {{ $category['name'] }}
                        </option>
                    @endforeach
                </select>

                <div class="flex gap-2">
                    <input type="search" name="keyword" value="{{ $filters['keyword'] ?? '' }}"
                           placeholder="Tìm ghi chú…" aria-label="Tìm theo ghi chú"
                           class="w-full sm:w-40 h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <button type="submit"
                            class="shrink-0 inline-flex items-center justify-center h-12 px-4 rounded-2xl bg-gray-800 hover:bg-gray-900 text-white text-sm font-bold transition-colors">
                        Lọc
                    </button>
                </div>
            </form>
        </div>

        {{-- ═══ Danh sách giao dịch ═══
             Mỗi dòng là một khối dọc: trên là ngày + danh mục + ghi chú + số tiền,
             dưới là thanh thao tác. Xếp dọc thay vì bảng nhiều cột vì trên điện
             thoại bảng 5 cột sẽ bóp chữ đến mức không đọc nổi. --}}
        <div x-data="creditCardHistory(@js($historyState))" class="space-y-3">
            <div x-show="error" x-cloak class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                <p class="text-sm text-red-700 break-words" x-text="error"></p>
            </div>

            @forelse ($rows as $row)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-3 min-w-0"
                     data-testid="transaction-row"
                     data-transaction-id="{{ $row['id'] }}">
                    <div class="flex items-start justify-between gap-3 min-w-0">
                        <div class="min-w-0 space-y-0.5">
                            <p class="font-semibold text-gray-800" data-field="date">
                                {{ \Illuminate\Support\Carbon::parse($row['transaction_date'])->format('d/m/Y') }}
                            </p>
                            {{-- Danh mục: in tên thật của giao dịch ngay trên dòng — trước đây row
                                 present() chỉ có `category_id` nên view không hiện nổi tên. --}}
                            <p class="text-xs font-medium text-gray-600 break-words" data-field="category"
                               @if (($row['category_name'] ?? null) === null) hidden @endif>{{ $row['category_name'] ?? '' }}</p>
                            {{-- Ghi chú: CHỈ hiện khi có nội dung. Hết in placeholder "Không có
                                 ghi chú" — `renderRow()` cập nhật phần tử này sau khi lưu. --}}
                            @if ($row['note'] !== null && trim($row['note']) !== '')
                                <p class="text-xs text-gray-500 break-words" data-field="note">{{ $row['note'] }}</p>
                            @else
                                <p class="text-xs text-gray-500 break-words" data-field="note" hidden></p>
                            @endif
                            {{-- Hoàn tiền: KHÔNG nối " đ" sau `x-credit-card.money` — hậu tố nằm sẵn
                                 trong component. Nối thêm ra "365.500 đ đ".
                                 Rỗng + hidden khi chưa có cashback: giữ sẵn phần tử để `renderRow()`
                                 hiện/ngắt sau khi lưu mà không tải lại trang. --}}
                            <p class="text-xs font-medium text-emerald-600" data-field="cashback"
                               @if ($row['cashback_amount'] === null) hidden @endif>
                                @if ($row['cashback_amount'] !== null)
                                    Hoàn tiền <x-credit-card.money :value="$row['cashback_amount']" />
                                @endif
                            </p>
                        </div>
                        <p class="shrink-0 font-bold text-gray-800 whitespace-nowrap"
                           data-field="amount"
                           data-testid="transaction-amount">
                            <x-credit-card.money :value="$row['amount']" />
                        </p>
                    </div>

                    @if (! $row['editable'])
                        <p class="text-xs text-gray-400 border-t border-gray-100 pt-3">
                            Kỳ đã chốt bảng kê — giao dịch đã được tính tiền, không sửa được.
                        </p>
                    @else
                        {{-- Thanh thao tác. Nút sửa mở form ngay trong dòng; nút
                             xoá hỏi lại trước khi gọi vì tiền thật. --}}
                        <div class="flex items-center gap-2 border-t border-gray-100 pt-3">
                            <button type="button"
                                    data-testid="edit-transaction"
                                    @click="openEdit({{ $row['id'] }})"
                                    class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-50 hover:bg-gray-100 text-gray-700 text-sm font-semibold transition-colors">
                                Sửa
                            </button>
                            <button type="button"
                                    data-testid="delete-transaction"
                                    @click="destroy({{ $row['id'] }})"
                                    :disabled="busyId === {{ $row['id'] }}"
                                    class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-red-50 hover:bg-red-100 text-red-600 text-sm font-semibold transition-colors disabled:opacity-50">
                                Xoá
                            </button>
                        </div>
                    @endif

                    {{-- Form sửa 4 ô, mở tại chỗ trong dòng. Không dùng <form> + POST
                         thật vì Alpine đã giữ state; gửi PATCH JSON cho khớp với
                         API và để gắn lỗi 422 đúng ô. --}}
                    <form x-show="editingId === {{ $row['id'] }}" x-cloak @submit.prevent="save({{ $row['id'] }})"
                          class="space-y-3 rounded-xl bg-gray-50 p-4 border border-gray-100" novalidate>
                        <p class="text-sm font-bold text-gray-800">Sửa giao dịch</p>

                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-600">Ngày giao dịch</label>
                            <input type="date" x-model="form.transaction_date"
                                   class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <p class="text-xs text-red-600" x-show="errors.transaction_date" x-cloak x-text="errors.transaction_date"></p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-600">Số tiền</label>
                            <x-credit-card.money-input expr="form.amount"
                                class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            <p class="text-xs text-red-600" x-show="errors.amount" x-cloak x-text="errors.amount"></p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-600">Danh mục</label>
                            <x-credit-card.searchable-select
                                x-model="form.category_id"
                                :options="$categoryOptions"
                                placeholder="— Chọn danh mục —"
                                search-placeholder="Tìm danh mục…"
                                empty-text="Không tìm thấy danh mục nào khớp."
                                class="w-full" />
                            <p class="text-xs text-red-600" x-show="errors.category_id" x-cloak x-text="errors.category_id"></p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-600">Ghi chú</label>
                            <textarea rows="2" maxlength="1000" x-model="form.note"
                                      class="w-full rounded-xl border-gray-300 text-base px-4 py-3 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                        </div>

                        <div class="flex items-center gap-2 pt-1">
                            <button type="submit" :disabled="busyId === {{ $row['id'] }}"
                                    class="inline-flex items-center justify-center h-12 px-4 rounded-xl bg-emerald-500 hover:bg-emerald-600 disabled:bg-emerald-300 disabled:cursor-not-allowed text-white text-sm font-bold transition-colors">
                                <span x-show="busyId !== {{ $row['id'] }}">Lưu</span>
                                <span x-show="busyId === {{ $row['id'] }}" x-cloak>Đang lưu…</span>
                            </button>
                            <button type="button" @click="closeEdit()"
                                    class="inline-flex items-center justify-center h-12 px-4 rounded-xl bg-white border border-gray-200 text-gray-600 text-sm font-semibold">
                                Huỷ
                            </button>
                        </div>
                    </form>
                </div>
            @empty
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center space-y-3">
                    <p class="text-sm text-gray-500">
                        @if ($total === 0 && empty($filters))
                            Thẻ này chưa có giao dịch nào.
                        @else
                            Không có giao dịch nào khớp bộ lọc.
                        @endif
                    </p>
                    @if ($total === 0 && empty($filters))
                        <a href="{{ route('credit-cards.index') }}"
                           class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold">
                            Nhập giao dịch ở Tổng quan
                        </a>
                    @endif
                </div>
            @endforelse

            {{-- Phân trang tối giản: nút Trước/Sau giữ nguyên bộ lọc. Danh sách
                 giao dịch người dùng hay xem ngắt quãng, không cần bộ phân trang
                 đầy đủ trên một trang. --}}
            @if ($lastPage > 1)
                <nav aria-label="Phân trang giao dịch"
                     class="flex items-center justify-between gap-3 bg-white rounded-2xl shadow-sm border border-gray-100 p-3">
                    @if ($page > 1)
                        <a href="{{ $queryFor($page - 1) }}"
                           class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-50 hover:bg-gray-100 text-gray-700 text-sm font-semibold">
                            Trước
                        </a>
                    @else
                        <span class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-50 text-gray-300 text-sm font-semibold">Trước</span>
                    @endif

                    <span class="text-xs text-gray-500">Trang {{ $page }}/{{ $lastPage }}</span>

                    @if ($page < $lastPage)
                        <a href="{{ $queryFor($page + 1) }}"
                           class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-50 hover:bg-gray-100 text-gray-700 text-sm font-semibold">
                            Sau
                        </a>
                    @else
                        <span class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-50 text-gray-300 text-sm font-semibold">Sau</span>
                    @endif
                </nav>
            @endif
        </div>
    </div>

    @once
        @include('credit-card.partials.money-js')

        <script>
            /**
             * Lịch sử giao dịch: sửa nhanh + xoá.
             *
             * Dữ liệu dòng và URL API đã có sẵn từ server, nên mở form sửa KHÔNG
             * cần gọi mạng — quan trọng trên mạng yếu.
             *
             * KHÔNG có công thức cashback ở đây: sau khi lưu, server tính lại và ghi
             * snapshot. Danh sách dòng là HTML render sẵn từ Blade (không x-for) nên
             * state Alpine KHÔNG tự đổi DOM — mọi thay đổi sau khi lưu/xoá phải đi
             * qua `renderRow()` (ghi lại text của đúng dòng) hoặc gỡ node (xoá),
             * kèm trừ `total` ở header. TUYỆT ĐỐI không `location.reload()`.
             */
            function creditCardHistory(state) {
                return {
                    rows: state.rows ?? [],
                    total: state.total ?? 0,
                    update_urls: state.update_urls ?? {},
                    delete_urls: state.delete_urls ?? {},
                    categories: state.categories ?? [],

                    editingId: null,
                    busyId: null,
                    error: '',
                    errors: {},
                    form: { transaction_date: '', amount: '', category_id: '', note: '' },

                    row(id) {
                        return this.rows.find((row) => row.id === Number(id)) ?? null;
                    },

                    /** '2026-10-07' → '07/10/2026'. Tách chuỗi, KHÔNG qua Date() (lệch múi giờ). */
                    formatDate(value) {
                        if (!value) return '';

                        const parts = String(value).split('-');

                        return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : String(value);
                    },

                    /**
                     * Ghi số server trả về vào đúng phần tử của dòng trong DOM.
                     *
                     * Rows là HTML tĩnh của Blade nên `rows.splice()` không đổi màn
                     * hình; hàm này là chỗ DUY NHẤT cập nhật hiển thị sau khi lưu.
                     * Text đặt bằng `textContent` (note/category đã escape sẵn
                     * server-side, không nối HTML).
                     */
                    renderRow(id) {
                        const row = this.row(id);
                        const node = document.querySelector(`[data-transaction-id="${Number(id)}"]`);

                        if (!row || !node) return;

                        const setText = (field, text) => {
                            const el = node.querySelector(`[data-field="${field}"]`);
                            if (el) el.textContent = text;
                        };

                        const toggle = (field, show) => {
                            const el = node.querySelector(`[data-field="${field}"]`);
                            if (el) el.hidden = !show;
                        };

                        setText('date', this.formatDate(row.transaction_date));

                        const categoryName = row.category_name ?? '';
                        toggle('category', categoryName !== '');
                        setText('category', categoryName);

                        const note = String(row.note ?? '');
                        toggle('note', note.trim() !== '');
                        setText('note', note);

                        const hasCashback = row.cashback_amount !== null && row.cashback_amount !== undefined;
                        toggle('cashback', hasCashback);
                        // Nhãn + số nằm cùng một phần tử: khi server đã render sẵn
                        // "Hoàn tiền <span>…</span>" thì ghi đè cả cụm vẫn ra đúng
                        // chữ người dùng thấy (cùng quy tắc với `x-credit-card.money`).
                        setText('cashback', hasCashback ? `Hoàn tiền ${ccMoneyVnd(row.cashback_amount)}` : '');

                        setText('amount', ccMoneyVnd(row.amount));
                    },

                    openEdit(id) {
                        const row = this.row(id);

                        if (!row || !row.editable) return;

                        this.error = '';
                        this.errors = {};
                        this.editingId = Number(id);
                        this.form = {
                            transaction_date: row.transaction_date ?? '',
                            amount: row.amount ?? '',
                            category_id: row.category_id ?? '',
                            note: row.note ?? '',
                        };
                    },

                    closeEdit() {
                        this.editingId = null;
                        this.errors = {};
                        this.error = '';
                    },

                    /**
                     * Chặn trước khi gọi mạng. Sửa tay vẫn nhận số dương như thêm
                     * mới (hoàn tiền thuộc luồng import); server chặn lại ở
                     * `UpdateTransactionRequest` với `gt:0`.
                     */
                    validate() {
                        const errors = {};
                        const amount = Number(this.form.amount);

                        if (this.form.amount === '' || !Number.isFinite(amount) || amount <= 0) {
                            errors.amount = 'Số tiền phải lớn hơn 0.';
                        }

                        if (!this.form.transaction_date) {
                            errors.transaction_date = 'Vui lòng chọn ngày giao dịch.';
                        }

                        this.errors = errors;

                        return Object.keys(errors).length === 0;
                    },

                    async save(id) {
                        this.error = '';

                        if (!this.validate()) return;

                        this.busyId = Number(id);

                        try {
                            const payload = await this.request(this.update_urls[id], {
                                method: 'PATCH',
                                body: JSON.stringify({
                                    transaction_date: this.form.transaction_date,
                                    amount: this.form.amount,
                                    category_id: this.form.category_id,
                                    note: this.form.note || null,
                                }),
                            });

                            if (!payload) return;

                            // Thay đúng dòng vừa sửa bằng số server trả về, nhưng
                            // GIỮ shape của dòng lịch sử (tiền là chuỗi) — payload
                            // API đã có đủ `category_id`/`category_name`/`editable`
                            // nên row vẫn mở lại được form sau khi lưu.
                            const index = this.rows.findIndex((row) => row.id === Number(id));
                            const data = payload.data ?? {};

                            if (index !== -1) {
                                this.rows.splice(index, 1, {
                                    ...this.rows[index],
                                    ...data,
                                    amount: String(data.amount ?? this.rows[index].amount),
                                    cashback_amount: data.cashback_amount === null || data.cashback_amount === undefined
                                        ? null
                                        : String(data.cashback_amount),
                                });

                                // DOM là HTML tĩnh của Blade: ghi lại số liệu của
                                // dòng ngay (ngày/tiền/danh mục/ghi chú/hoàn tiền).
                                this.renderRow(id);
                            }

                            this.editingId = null;
                        } finally {
                            this.busyId = null;
                        }
                    },

                    async destroy(id) {
                        this.error = '';

                        // Xoá mất tiền, hỏi lại một lần là đủ — không dùng prompt
                        // tự dựng vì trên điện thoại `confirm()` gốc chặn luồng.
                        if (!window.confirm('Xoá giao dịch này? Số tiền và hoàn tiền sẽ được tính lại.')) {
                            return;
                        }

                        this.busyId = Number(id);

                        try {
                            const payload = await this.request(this.delete_urls[id], { method: 'DELETE' });

                            if (!payload) return;

                            this.rows = this.rows.filter((row) => row.id !== Number(id));

                            // Row là HTML tĩnh của Blade — state đổi chưa đủ: gỡ
                            // luôn node và trừ số ở header để UI khớp ngay (không
                            // reload trang).
                            document.querySelector(`[data-transaction-id="${Number(id)}"]`)?.remove();

                            this.total = Math.max(0, Number(this.total) - 1);

                            if (this.editingId === Number(id)) {
                                this.editingId = null;
                            }
                        } finally {
                            this.busyId = null;
                        }
                    },

                    async request(url, options = {}) {
                        try {
                            const response = await fetch(url, {
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                },
                                ...options,
                            });

                            const payload = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                if (payload.errors) {
                                    this.errors = {
                                        transaction_date: this.firstOf(payload.errors.transaction_date),
                                        amount: this.firstOf(payload.errors.amount),
                                        category_id: this.firstOf(payload.errors.category_id),
                                        note: this.firstOf(payload.errors.note),
                                    };

                                    this.error = 'Vui lòng kiểm tra lại các ô được đánh dấu.';
                                } else {
                                    // 403 = kỳ đã chốt; nói rõ thay vì "lỗi 403".
                                    this.error = response.status === 403
                                        ? 'Giao dịch thuộc kỳ đã chốt nên không sửa được.'
                                        : (this.firstError(payload) || `Yêu cầu thất bại (${response.status}).`);
                                }

                                return null;
                            }

                            return payload;
                        } catch (e) {
                            this.error = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                            return null;
                        }
                    },

                    firstOf(list) {
                        return Array.isArray(list) && list.length > 0 ? list[0] : '';
                    },

                    firstError(payload) {
                        if (payload.message) return payload.message;

                        if (payload.errors) {
                            const first = Object.values(payload.errors)[0];
                            if (Array.isArray(first)) return first[0];
                        }

                        return null;
                    },
                };
            }
        </script>
    @endonce

</x-credit-card.layout>
