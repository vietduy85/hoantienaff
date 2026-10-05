{{--
    Sao kê THỰC TẾ — /thetindung/sao-ke

    ---------------------------------------------------------------------------
    KHÁC "LỊCH SỬ GIAO DỊCH"
    ---------------------------------------------------------------------------
    Giao dịch là thứ user nhập và engine cashback dùng để tính. Sao kê ở đây là con
    số user ĐỌC trên bảng kê của ngân hàng: nó có thể lệch với giao dịch (thiếu phiếu,
    sai số tiền), và việc nhập nó KHÔNG được động vào cashback. Vì thế trang này không
    có ô cashback dự kiến, không có %/rule/tier, và không import engine.

    ---------------------------------------------------------------------------
    DANH SÁCH RENDER Ở SERVER, JAVASCRIPT CHỈ LÀM LỊCH
    ---------------------------------------------------------------------------
    Cùng nguyên tắc trang Lịch sử giao dịch: `@forelse` in sẵn mọi dòng, Alpine chỉ
    mở form và làm mới đúng dòng vừa sửa. Không dựng danh sách bằng `<template x-for>`
    — làm vậy thì người không bật JavaScript ra trang trắng, và bộ máy tìm kiếm/
    trình đọc màn hình không đọc được nội dung mà trang này vốn dành cho chính họ.

    ---------------------------------------------------------------------------
    "SỐ DƯ CÒN PHẢI TRẢ" LUÔN LÀ SỐ SERVER TÍNH
    ---------------------------------------------------------------------------
    Cột này in `closing_balance` mà server gửi, KHÔNG phải `spend - reward` tính trong
    trình duyệt: hai nơi cùng một công thức là chỗ chắc chắn phát sinh lệch. Vì thế form
    nhập không có dòng xem trước — người dùng lưu xong mới thấy, và thấy đúng số sẽ ghi.

    ---------------------------------------------------------------------------
    MỞ TRANG KHÔNG ĐƯỢC GHI
    ---------------------------------------------------------------------------
    Controller dựng danh sách bằng `findForDate()` (chỉ đọc). Thẻ chưa có sao kê vẫn
    hiện một dòng kèm kỳ và hạn thanh toán, để thấy "đang nợ kỳ nào" trước khi nhập.
--}}
<x-credit-card.layout title="Sao kê" subtitle="Số tiền thực tế trên bảng kê của ngân hàng, theo từng kỳ."
                    active="statements">

    @include('credit-card.partials.money-js')

    @include('credit-card.partials.sort-picker', [
        'sortModes' => $sortModes,
        'sortMode' => $sortMode,
        'sortAction' => $sortAction,
        'sortStorageKey' => $sortStorageKey,
    ])

    <div x-data="creditCardStatements(@js(['urls' => $urls]))" class="space-y-3 sm:space-y-4">

        <div x-show="error" x-cloak class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
            <p class="text-sm text-red-700 break-words" x-text="error"></p>
        </div>

        @forelse ($rows as $row)
            @php
                $statement = $row['statement'];
                $dueState = $row['period']['due_state'] ?? 'none';
                $daysToDue = $row['period']['days_to_due'] ?? null;
            @endphp

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-3 min-w-0"
                 data-testid="statement-row"
                 data-card-id="{{ $row['id'] }}">

                {{-- ═══ THẺ ═══
                     Chỉ 4 số cuối: module không lưu số thẻ đầy đủ ở đâu cả. --}}
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 min-w-0">
                    <span class="font-semibold text-gray-800">{{ $row['name'] }}</span>
                    @if ($row['bank'] !== null && $row['bank'] !== '')
                        <span class="text-xs text-gray-500">{{ $row['bank'] }}</span>
                    @endif
                    @if ($row['card_number_last4'] !== null && $row['card_number_last4'] !== '')
                        <span class="text-xs text-gray-400 tabular-nums">•••• {{ $row['card_number_last4'] }}</span>
                    @endif
                </div>

                {{-- ═══ KỲ SAO KÊ ═══
                     In từ `period_bounds` (kỳ server suy ra ra), không phải `period`:
                     lần đầu mở trang chưa có bản ghi kỳ nào, nhưng người dùng vẫn
                     cần biết đang nhập vào kỳ nào. --}}
                <p class="text-xs text-gray-500 tabular-nums">
                    Kỳ {{ $row['period_bounds']['start_label'] }}
                    &ndash; {{ $row['period_bounds']['end_label'] }}
                </p>

                {{-- ═══ HẠN THANH TOÁN ═══
                     Màu và chữ "quá hạn/còn N ngày" đến từ `due_state` của SERVER, view
                     không tự so sánh ngày — nơi đếm ngày lệch nhau là nơi sinh cảnh báo sai. --}}
                <p class="text-xs">
                    @if ($dueState === 'none')
                        <span class="text-gray-400">Chưa có hạn thanh toán</span>
                    @else
                        <span @class([
                            'font-semibold',
                            'text-red-700' => in_array($dueState, ['overdue', 'today'], true),
                            'text-amber-700' => $dueState === 'soon',
                            'text-gray-600' => $dueState === 'later',
                        ])>
                            Đến hạn {{ \Illuminate\Support\Carbon::parse($row['period']['due_date'])->format('d/m/Y') }}
                            @if ($dueState === 'overdue')
                                &middot; quá hạn {{ abs($daysToDue) }} ngày
                            @elseif ($dueState === 'today')
                                &middot; hôm nay
                            @elseif ($dueState === 'soon')
                                &middot; còn {{ $daysToDue }} ngày
                            @endif
                        </span>
                    @endif
                </p>

                {{-- ═══ SỐ LIỆU ═══
                     Chưa nhập hiện "Chưa nhập"/"—", KHÔNG hiện 0: chưa có sao kê không
                     đồng nghĩa với sao kê bằng 0. --}}
                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-2 pt-1">
                    <div>
                        <dt class="text-xs text-gray-500">Chi tiêu thực tế</dt>
                        <dd class="font-semibold text-gray-800 tabular-nums" data-testid="statement-spend">
                            @if ($statement === null)
                                <span class="text-gray-400">Chưa nhập</span>
                            @else
                                {{-- Hoàn tiền: KHÔNG nối " đ" sau `x-credit-card.money` —
                                     hậu tố nằm sẵn trong component. --}}
                                <x-credit-card.money :value="$statement['actual_spend']" />
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs text-gray-500">Hoàn/thưởng thực tế</dt>
                        <dd class="font-semibold text-gray-800 tabular-nums" data-testid="statement-reward">
                            @if ($statement === null)
                                <span class="text-gray-400">Chưa nhập</span>
                            @else
                                <x-credit-card.money :value="$statement['actual_reward']" />
                            @endif
                        </dd>
                    </div>

                    <div class="col-span-2 sm:col-span-1 sm:text-right">
                        <dt class="text-xs text-gray-500">Còn phải trả</dt>
                        {{-- `data-statement-id` để JS biết dòng này ĐÃ có sao kê hay
                             chưa (POST khi nhập, PATCH khi sửa) mà không phải giữ
                             thêm một bản sao danh sách trong state. --}}
                        <dd class="font-bold text-gray-900 tabular-nums"
                            data-testid="statement-closing-balance"
                            data-statement-id="{{ $statement['id'] ?? '' }}">
                            @if ($statement === null)
                                <span class="text-gray-400">&mdash;</span>
                            @else
                                <x-credit-card.money :value="$statement['closing_balance']" />
                            @endif
                        </dd>
                    </div>
                </dl>

                @if (! $row['editable'])
                    <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5">
                        Kỳ đã chốt, không sửa được
                    </p>
                @else
                    {{-- Thanh thao tác. Nút sửa mở form ngay trong dòng; nút xoá hỏi
                         lại trước khi gọi vì đây là tiền thật. --}}
                    <div class="flex items-center gap-2 border-t border-gray-100 pt-3">
                        <button type="button"
                                data-testid="statement-edit-{{ $row['id'] }}"
                                @click="openEdit({{ $row['id'] }})"
                                :disabled="busyId === {{ $row['id'] }}"
                                class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-50
                                       hover:bg-gray-100 text-gray-700 text-sm font-semibold transition-colors
                                       disabled:opacity-50">
                            {{ $statement === null ? 'Nhập sao kê' : 'Sửa' }}
                        </button>

                        @if ($statement !== null)
                            <button type="button"
                                    data-testid="statement-delete-{{ $row['id'] }}"
                                    @click="destroy({{ $row['id'] }})"
                                    :disabled="busyId === {{ $row['id'] }}"
                                    class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-red-50
                                           hover:bg-red-100 text-red-600 text-sm font-semibold transition-colors
                                           disabled:opacity-50">
                                Xoá
                            </button>
                        @endif
                    </div>

                    {{-- Form 2 ô, mở tại chỗ trong dòng. Không dùng <form> + POST thật
                         vì Alpine đã giữ state; gửi JSON để khớp API và gắn lỗi 422
                         đúng ô. --}}
                    <div x-show="openId === {{ $row['id'] }}" x-cloak class="border-t border-gray-100 pt-3 space-y-3">
                        <form @submit.prevent="save({{ $row['id'] }})" novalidate class="space-y-3">
                            <div>
                                <label for="statement-spend-{{ $row['id'] }}"
                                       class="block text-sm font-medium text-gray-700">
                                    Chi tiêu thực tế <span class="text-red-500">*</span>
                                </label>
                                <input id="statement-spend-{{ $row['id'] }}" name="actual_spend"
                                       type="text" inputmode="decimal" autocomplete="off"
                                       value="{{ $statement['actual_spend'] ?? '' }}"
                                       :disabled="busyId === {{ $row['id'] }}"
                                       class="mt-1 w-full h-11 px-3 rounded-xl border-gray-200 text-sm tabular-nums
                                              focus:border-emerald-500 focus:ring-emerald-500"
                                       :class="errors.actual_spend ? 'border-red-400' : ''"
                                       placeholder="0">
                                <p class="mt-1 text-xs text-red-600" x-show="errors.actual_spend" x-cloak
                                   x-text="errors.actual_spend"></p>
                            </div>

                            <div>
                                <label for="statement-reward-{{ $row['id'] }}"
                                       class="block text-sm font-medium text-gray-700">
                                    Hoàn/thưởng thực tế <span class="text-red-500">*</span>
                                </label>
                                <input id="statement-reward-{{ $row['id'] }}" name="actual_reward"
                                       type="text" inputmode="decimal" autocomplete="off"
                                       value="{{ $statement['actual_reward'] ?? '' }}"
                                       :disabled="busyId === {{ $row['id'] }}"
                                       class="mt-1 w-full h-11 px-3 rounded-xl border-gray-200 text-sm tabular-nums
                                              focus:border-emerald-500 focus:ring-emerald-500"
                                       :class="errors.actual_reward ? 'border-red-400' : ''"
                                       placeholder="0">
                                <p class="mt-1 text-xs text-red-600" x-show="errors.actual_reward" x-cloak
                                   x-text="errors.actual_reward"></p>
                            </div>

                            {{-- Cố tình KHÔNG có dòng "xem trước số còn phải trả": nó sẽ là
                                 công thức thứ hai của cùng một phép trừ, và chỗ hai nơi
                                 tính tiền là chỗ sinh lệch. Số này hiện ở trên, lấy từ
                                 server. --}}
                            <p class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">
                                Số tiền còn phải trả sẽ được hệ thống tính và hiển thị ngay dòng trên sau khi lưu.
                            </p>

                            <div class="flex items-center justify-end gap-2">
                                <button type="button" @click="closeEdit({{ $row['id'] }})"
                                        class="h-10 px-4 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-100">
                                    Huỷ
                                </button>
                                <button type="submit"
                                        :disabled="busyId === {{ $row['id'] }}"
                                        class="h-10 px-5 rounded-xl text-sm font-semibold text-white bg-emerald-600
                                               hover:bg-emerald-700 disabled:opacity-60">
                                    <span x-text="busyId === {{ $row['id'] }} ? 'Đang lưu…' : 'Lưu'"></span>
                                </button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-4 py-10 text-center">
                <p class="text-sm text-gray-500">Bạn chưa có thẻ tín dụng nào để lấy sao kê.</p>
                <a href="{{ route('credit-cards.manage') }}"
                   class="mt-3 inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold">
                    Thêm thẻ ở Quản lý thẻ
                </a>
            </div>
        @endforelse
    </div>
</x-credit-card.layout>

@once
    <script>
        /**
         * Sao kê thực tế — nhập / sửa / xoá trên API.
         *
         * Controller KHÔNG orchestration gì ở đây: thứ tự thẻ, kỳ sao kê, quyền sở
         * hữu và số "còn phải trả" đều do server trả về. Trình duyệt chỉ gửi hai ô
         * người dùng thực sự nhập, rồi đọc lại đúng số server đã ghi.
         */
        function creditCardStatements(state) {
            return {
                urls: state.urls ?? {},

                /** id thẻ đang mở form; `null` = không dòng nào mở. */
                openId: null,
                /** id thẻ đang gọi API, để khoá nút cho đúng dòng đang bận. */
                busyId: null,
                error: '',
                errors: {},

                init() {
                    // Form nằm trong DOM sẵn (render ở server), nên giá trị khởi tạo
                    // lấy thẳng từ input thay vì giữ một bản sao trong state.
                    this.$watch('openId', (id) => {
                        if (id === null) this.errors = {};
                    });
                },

                openEdit(cardId) {
                    this.error = '';
                    this.errors = {};
                    this.openId = this.openId === cardId ? null : cardId;
                },

                closeEdit(cardId) {
                    if (this.busyId === cardId) return;
                    if (this.openId === cardId) this.openId = null;
                },

                formOf(cardId) {
                    const root = document.getElementById(`statement-spend-${cardId}`)?.closest('form');

                    if (!root) return null;

                    return {
                        actual_spend: root.querySelector('[name="actual_spend"]')?.value ?? '',
                        actual_reward: root.querySelector('[name="actual_reward"]')?.value ?? '',
                    };
                },

                /** Có dòng sao kê chưa — quyết định POST (nhập) hay PATCH (sửa). */
                statementUrl(cardId) {
                    const cell = document.querySelector(
                        `[data-card-id="${cardId}"] [data-testid="statement-closing-balance"]`,
                    );

                    const id = cell?.dataset.statementId;

                    if (! id) {
                        return { url: this.bind(this.urls.store, cardId), method: 'POST' };
                    }

                    return {
                        url: this.bind(this.urls.update, Number(id)),
                        method: 'PATCH',
                    };
                },

                async save(cardId) {
                    if (this.busyId !== null) return;

                    const payload = this.formOf(cardId);

                    if (payload === null) return;

                    this.busyId = cardId;
                    this.error = '';
                    this.errors = {};

                    const { url, method } = this.statementUrl(cardId);

                    const result = await this.request(url, {
                        method,
                        body: JSON.stringify(payload),
                    });

                    this.busyId = null;

                    if (! result) return;

                    // Ghi đè đúng dòng vừa sửa bằng số server trả về, thay vì tự
                    // cộng/trừ trong trình duyệt — nơi duy nhất có số chuẩn.
                    this.applyStatement(cardId, result.data);
                    this.openId = null;
                },

                async destroy(cardId) {
                    if (this.busyId !== null) return;

                    const cell = document.querySelector(
                        `[data-card-id="${cardId}"] [data-testid="statement-closing-balance"]`,
                    );
                    const statementId = cell?.dataset.statementId;

                    // Chưa có dòng sao kê thì không có gì để xoá.
                    if (! statementId) return;

                    if (! window.confirm('Xoá sao kê của kỳ này?')) return;

                    this.busyId = cardId;
                    this.error = '';

                    const result = await this.request(this.bind(this.urls.destroy, Number(statementId)), {
                        method: 'DELETE',
                    });

                    this.busyId = null;

                    if (! result) return;

                    this.applyStatement(cardId, null);
                },

                /**
 * Cập nhật số hiển thị của đúng một dòng.
                 *
                 * `statement === null` = dòng CHƯA có sao kê. Khi đó phải dọn luôn
                 * `data-statement-id`, ô nhập, nhãn nút và nút xoá — không chỉ thay
                 * chữ. Nếu để sót id thì lần mở kế tiếp `statementUrl()` vẫn trả
                 * PATCH tới một dòng đã xoá (404), và nhãn "Sửa"/nút "Xoá" mô tả sai
                 * trạng thái thật của dòng.
                 */
                applyStatement(cardId, statement) {
                    const row = document.querySelector(`[data-card-id="${cardId}"]`);

                    if (! row) return;

                    const set = (testId, text, muted) => {
                        const node = row.querySelector(`[data-testid="${testId}"]`);

                        if (! node) return;

                        node.textContent = text;
                        node.classList.toggle('text-gray-400', muted);
                    };

                    const closing = row.querySelector('[data-testid="statement-closing-balance"]');

                    if (statement === null) {
                        set('statement-spend', 'Chưa nhập', true);
                        set('statement-reward', 'Chưa nhập', true);

                        if (closing) {
                            closing.dataset.statementId = '';
                            closing.textContent = '—';
                            closing.classList.add('text-gray-400');
                        }

                        for (const name of ['actual_spend', 'actual_reward']) {
                            const input = row.querySelector(`[name="${name}"]`);

                            if (input) input.value = '';
                        }

                        const edit = row.querySelector('[data-testid^="statement-edit-"]');

                        if (edit) edit.textContent = 'Nhập sao kê';

                        // Nút "Xoá" chỉ tồn tại khi dòng đã có sao kê (render ở server).
                        row.querySelector('[data-testid^="statement-delete-"]')?.remove();

                        this.errors = {};

                        return;
                    }

                    // Ngược lại: ghi id mà server vừa trả. Bỏ trống ô này thì lần sửa
                    // kế tiếp vẫn đi theo POST và đụng UNIQUE (mỗi cặp thẻ/kỳ chỉ
                    // một dòng) — tức là vừa nhập xong là sửa tiếp sẽ lỗi.
                    if (closing) {
                        closing.dataset.statementId = String(statement.id ?? '');
                        closing.classList.remove('text-gray-400');
                    }

                    set('statement-spend', ccMoneyVnd(statement.actual_spend), false);
                    set('statement-reward', ccMoneyVnd(statement.actual_reward), false);
                    set('statement-closing-balance', ccMoneyVnd(statement.closing_balance), false);
                },

                /** URL mẫu có `0` giữ chỗ; chỉ thay đúng số 0 đó. */
                bind(template, id) {
                    return String(template ?? '').replace(/\/0(?=\/|$)/, '/' + id);
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

                        if (! response.ok) {
                            // 422 ⇒ gắn lỗi đúng ô để biết sửa chỗ nào, KHÔNG in
                            // `message` mặc định của Laravel (tiếng Anh).
                            if (payload.errors) {
                                this.errors = {
                                    actual_spend: this.firstOf(payload.errors.actual_spend),
                                    actual_reward: this.firstOf(payload.errors.actual_reward),
                                };

                                this.error = 'Vui lòng kiểm tra lại các ô được đánh dấu.';
                            } else {
                                this.error = this.firstError(payload)
                                    || `Yêo cầu thất bại (${response.status}).`;
                            }

                            return null;
                        }

                        return payload;
                    } catch (e) {
                        // KHÔNG lộ exception/stack trace ra UI.
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
