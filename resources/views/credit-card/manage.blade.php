{{--
    /thetindung/quan-ly-the — Thêm / Sửa thẻ tín dụng (mobile-first).

    ---------------------------------------------------------------------------
    VÌ SAO MỘT TRANG, KHÔNG TÁCH MÀN HÌNH RIÊNG
    ---------------------------------------------------------------------------
    Người dùng trên điện thoại phải làm việc bằng MỘT tay và thườt phải điền
    ngay sau khi cầm thẻ ra khỏi ví. Form nằm ngay dưới danh sách, mở ra tại
    chỗ nên bấm được bằng ngón cái, không phải điều hướng sang URL khác rồi quay
    lại.

    ---------------------------------------------------------------------------
    VÌ SAO 1 CỘT, KHÔNG 2 CỘT
    ---------------------------------------------------------------------------
    Mọi hàng form là `grid-cols-1`; cặp ngày chỉ tách cột từ `sm` trở lên.
    Trên máy tính thì hẹp hơn chút là đủ — đổi lại không bao giờ phải cuộn
    ngang trên màn 360px.

    ---------------------------------------------------------------------------
    VÌ SAO DÙNG `text-base` TRÊN INPUT
    ---------------------------------------------------------------------------
    iOS tự phóng to chữ khi focus vào input có font-size < 16px, làm trang bị
    nhảy. `text-base` (16px) là mức nhỏ nhất không gây nhảy.

    ---------------------------------------------------------------------------
    VÌ SAO SERVER TÍNH NGÀY KẾT THÚC
    ---------------------------------------------------------------------------
    Ô "Ngày kết thúc" chỉ hiển thị, không gửi lên. Công thức
    `end = start + 1 tháng − 1 ngày` nằm ở `UserCardService::statementPeriodEnd()`
    (nguồn sự thật duy nhất); JS chỉ chạy BẢN SAO để hiện số tức thì khi user
    chọn ngày. Hai bản phải giống nhau — xem `periodEnd()` bên dưới.
--}}
<x-credit-card.layout
    title="Quản lý thẻ"
    subtitle="Thêm, sửa và chọn chính sách hoàn tiền cho từng thẻ"
    active="manage">

    @php
        /*
         * State khởi tạo cho Alpine: danh sách thẻ + ngân hàng + mẫu chính sách.
         *
         * Mọi `id` giữ kiểu SỐ, khớp với JSON API trả về sau khi lưu — xem chú thích
         * bên dưới về lý do so sánh nghiêm ngặt của Alpine.
         */
        $initialState = [
            // `id` giữ kiểu SỐ: đây cũng là kiểu API trả về sau khi lưu. Nếu một
            // bên ép sang chuỗi thì `form.bank_id === bank.id` (so sánh nghiêm ngặt
            // của Alpine) sẽ hỏng ⇒ ô ngân hàng đã chọn mất viền sau khi lưu.
            'banks' => $banks
                ->map(fn ($bank) => [
                    'id' => (int) $bank->id,
                    'name' => $bank->name,
                    'short_name' => $bank->short_name,
                    // `slug` + `aliases` để ô tìm kiếm tra được cả mã canonical
                    // lẫn mã cũ (ví dụ gõ "scb" ra Sacombank, "stb" cũng ra).
                    'slug' => $bank->slug,
                    'aliases' => array_values((array) $bank->aliases),
                ])
                ->values(),
            'cards' => $userCreditCards
                ->map(fn ($card) => [
                    'id' => (int) $card->id,
                    'name' => $card->name,
                    'bank' => $card->bank === null ? null : ['id' => (int) $card->bank->id, 'name' => $card->bank->name],
                    'statement_period_start' => $card->statement_period_start?->toDateString(),
                    'statement_period_end' => $card->statement_period_end?->toDateString(),
                    'payment_due_day' => $card->payment_due_day,
                    'spending_deadline_day' => $card->spending_deadline_day,
                    'desired_spend' => $card->desired_spend,
                    'promotion_info' => $card->promotion_info,
                    'note' => $card->note,
                ])
                ->values(),
            'templates' => $policyTemplates
                ->map(fn ($template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'is_system' => $template->isSystemScope(),
                ])
                ->values(),
            // Danh sách target cho Policy Editor chính thức. Render SẴN từ server
            // (đã lọc theo user ở `CreditCardController::manage()`) thay vì gọi API
            // khi mở form: mở form trên mạng yếu không phải chờ thêm round-trip, và
            // danh sách chỉ có một nguồn — không thể lệch với những gì server validate.
            'ruleCategories' => $ruleCategories,
            'ruleCombos' => $ruleCombos,
        ];
    @endphp

    <div x-data="creditCardManager(@js($initialState))" class="space-y-4">

        {{-- ═══ Danh sách thẻ ═══ --}}
        <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-4">
            {{--
                Tiêu đề + nút "+ Thêm thẻ".

                Nút render SẴN ở phía server, nằm NGOÀI mọi nhánh `@if` và không
                phụ thuộc `cards.length` ⇒ mới vào trang (chưa có thẻ) vẫn thấy nút.

                `x-show="! form.open"` CHỈ ẩn nút khi form đang mở. Cố tình KHÔNG
                thêm `x-cloak`: trước lúc Alpine nạp xong nút vẫn hiện, tránh nháy
                trắng rồi mới hiện nút. `form.open` là boolean thật (khai trong
                `ccBlankCardForm()`) — trước đây nó là `undefined` nên `! undefined`
                luôn đúng và nút không bao giờ biến mất, còn form thì không bao giờ
                mở ra.
            --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h3 class="font-bold text-gray-800 text-base">Thẻ của bạn</h3>
                    <p class="text-xs text-gray-500 sm:hidden">Chạm vào một thẻ để sửa.</p>
                </div>

                {{-- Mobile: full-width dưới tiêu đề. Từ `sm` lên: bên phải tiêu đề. --}}
                <button type="button"
                        data-testid="add-card-button"
                        x-show="! form.open"
                        @click="openCreate()"
                        class="w-full sm:w-auto shrink-0 inline-flex items-center justify-center gap-2 h-14 sm:h-12 px-5 rounded-2xl bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-base font-bold shadow-sm transition-colors">
                    <span aria-hidden="true" class="text-xl leading-none">+</span> Thêm thẻ
                </button>
            </div>

            @if ($userCreditCards->isEmpty())
                <div class="rounded-xl border border-dashed border-gray-200 py-10 px-4 text-center">
                    <p class="text-4xl mb-2" aria-hidden="true">💳</p>
                    <p class="text-sm text-gray-600 font-medium">Chưa có thẻ nào</p>
                    <p class="text-xs text-gray-400 mt-1">Bấm nút “+ Thêm thẻ” để bắt đầu.</p>
                </div>
            @else
                <ul class="space-y-3">
                    @foreach ($userCreditCards as $card)
                        <li class="rounded-xl border border-gray-200 p-4 space-y-3">
                            {{-- Tên + ngân hàng --}}
                            <div class="flex items-start justify-between gap-2 min-w-0">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-800 break-words">
                                        {{ $card->name ?: 'Thẻ tín dụng' }}
                                    </p>
                                    <p class="text-xs text-gray-500 mt-0.5 break-words">
                                        {{ $card->bank?->name ?? 'Chưa biết ngân hàng' }}
                                        @if ($card->status !== \App\Models\CreditCard\UserCard::STATUS_ACTIVE)
                                            <span class="ml-1 inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600">Đã đóng</span>
                                        @endif
                                    </p>
                                </div>
                                <button type="button"
                                        @click="openEdit({{ $card->id }})"
                                        class="shrink-0 inline-flex items-center justify-center h-11 px-4 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm font-semibold active:bg-emerald-100 transition-colors">
                                    Sửa
                                </button>
                            </div>

                            {{-- Số liệu chính: bọc w-full để không đẩy ngang --}}
                            <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                                <div class="min-w-0">
                                    <dt class="text-gray-400">Kỳ sao kê</dt>
                                    <dd class="text-gray-700 font-medium break-words">
                                        @if ($card->statement_period_start)
                                            {{ $card->statement_period_start->format('d/m') }}–{{ $card->statement_period_end->format('d/m/Y') }}
                                        @else
                                            <span class="text-gray-300">Chưa khai</span>
                                        @endif
                                    </dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-gray-400">Chính sách hoàn tiền</dt>
                                    <dd class="text-gray-700 font-medium break-words">
                                        {{ $card->currentPolicy?->name ?? 'Chưa chọn' }}
                                    </dd>
                                </div>
                            </dl>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ═══ Form thêm / sửa ═══ --}}
        <form x-show="form.open" x-cloak @submit.prevent="save()" class="space-y-4" novalidate>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-5">

                {{-- Tiêu đề + nút đóng --}}
                <div class="flex items-center justify-between gap-3">
                    <h3 class="font-bold text-gray-800 text-base"
                        x-text="form.id ? 'Sửa thẻ' : 'Thêm thẻ'"></h3>
                    <button type="button" @click="closeForm()"
                            class="shrink-0 inline-flex items-center justify-center h-10 w-10 rounded-xl border border-gray-200 text-gray-500 text-lg leading-none"
                            aria-label="Đóng">&times;</button>
                </div>

                {{-- Thông báo --}}
                <div x-show="error" x-cloak class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                    <p class="text-sm text-red-700 break-words" x-text="error"></p>
                </div>
                <div x-show="notice" x-cloak class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                    <p class="text-sm text-emerald-700 break-words" x-text="notice"></p>
                </div>

                {{-- Tên thẻ --}}
                <div class="space-y-1.5">
                    <label for="cc-name" class="block text-sm font-semibold text-gray-700">Tên thẻ</label>
                    <input id="cc-name" type="text" x-model="form.name" required maxlength="150"
                           placeholder="Ví dụ: Thẻ MB chính"
                           class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <p class="text-xs text-gray-500">Tên do bạn đặt để dễ nhận ra thẻ này.</p>
                </div>

                {{-- Ngân hàng phát hành — SEARCHABLE COMBO BOX --}}
                {{--
                    Migration 22 mở `credit_card_user_cards.bank_id` thành NULLABLE
                    (FK vẫn giữ: có giá trị thì phải là bank thật). Người dùng được khai
                    thẻ trước khi biết ngân hàng, nên luôn có lựa chọn bỏ trống.

                    Vì sao KHÔNG dùng lưới 43 ô: 43 nút chiếm hết cả màn hình điện
                    thoại, phải cuộn dài và không tìm được nhanh. Combo box lọc theo
                    tên/mã nên một tay cũng xong.

                    "Chưa biết / Không chọn" và "Ngân hàng khác" CÙNG lưu
                    `bank_id = NULL` (mục đích chung: chưa xác định được ngân hàng),
                    và đều KHÔNG tạo bản ghi bank giả. Người dùng sửa thẻ sau sẽ
                    chọn lại được bất cứ lúc nào.
                --}}
                <div class="space-y-1.5 relative" @click.outside="closeBankPicker()">
                    <span class="block text-sm font-semibold text-gray-700">
                        Ngân hàng phát hành
                        <span class="font-normal text-gray-400">(không bắt buộc)</span>
                    </span>

                    {{-- Ô đã chọn: mở dropdown, KHÔNG phải <select> để tự kiểm soát
                         chiều cao & ô tìm kiếm trên mobile. --}}
                    <button type="button"
                            id="cc-bank-trigger"
                            data-testid="bank-selector"
                            data-bank-searchable="true"
                            @click="toggleBankPicker()"
                            :aria-expanded="bankPickerOpen ? 'true' : 'false'"
                            aria-haspopup="listbox"
                            class="w-full min-h-12 rounded-xl border border-gray-300 bg-white text-base px-4 py-3 shadow-sm flex items-center justify-between gap-2 text-left focus:border-emerald-500 focus:ring-emerald-500">
                        <span class="truncate" :class="form.bank_id === '' ? 'text-gray-400' : 'text-gray-800'"
                              x-text="selectedBankLabel"></span>
                        <span class="shrink-0 text-gray-400" aria-hidden="true">
                            <span x-show="! bankPickerOpen">▾</span>
                            <span x-show="bankPickerOpen" x-cloak>▴</span>
                        </span>
                    </button>

                    {{-- Giá trị thật đi vào payload. Ô này KHÔNG render option vì
                         không muốn gửi `bank_id=''` (chuỗi rỗng) — rỗng = chưa chọn. --}}
                    <input type="hidden" name="bank_id" :value="form.bank_id === '' ? '' : form.bank_id">

                    {{-- Dropdown: absolute + z-50 nên không bị container cắt.
                         `max-h` co theo viewport để bàn phím mobile mở vẫn thấy danh sách. --}}
                    <div x-show="bankPickerOpen"
                         x-cloak
                         @keydown.escape.stop="closeBankPicker()"
                         class="absolute z-50 left-0 right-0 mt-1 rounded-xl border border-gray-200 bg-white shadow-lg overflow-hidden">
                        <div class="p-2 border-b border-gray-100">
                            <input type="search"
                                   x-ref="bankSearch"
                                   x-model="bankQuery"
                                   data-testid="bank-search"
                                   placeholder="Tìm theo tên hoặc mã (VIB, SCB…)"
                                   autocomplete="off"
                                   autocapitalize="off"
                                   autocorrect="off"
                                   spellcheck="false"
                                   class="w-full min-h-12 rounded-lg border border-gray-300 text-base px-3 py-2 focus:border-emerald-500 focus:ring-emerald-500">
                        </div>

                        <ul role="listbox" class="max-h-[min(16rem,45vh)] overflow-y-auto overscroll-contain py-1">
                            {{-- "Chưa biết" luôn ở đầu: chạm được ngay bằng một tay. --}}
                            <li role="option" :aria-selected="form.bank_id === '' ? 'true' : 'false'">
                                <button type="button" @click="pickBank('')"
                                        class="w-full min-h-12 px-4 py-2.5 text-left flex items-center justify-between gap-2"
                                        :class="form.bank_id === '' ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-600'">
                                    <span>Chưa biết / Không chọn</span>
                                </button>
                            </li>

                            <template x-for="bank in filteredBanks" :key="bank.id">
                                <li role="option" :aria-selected="form.bank_id === bank.id ? 'true' : 'false'">
                                    <button type="button" @click="pickBank(bank.id)"
                                            class="w-full min-h-12 px-4 py-2.5 text-left flex items-center justify-between gap-2"
                                            :class="form.bank_id === bank.id
                                                ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                                : 'text-gray-700 active:bg-gray-50'">
                                        <span class="min-w-0 truncate" x-text="bank.name"></span>
                                        <span class="shrink-0 text-xs text-gray-400" x-text="bank.short_name"></span>
                                    </button>
                                </li>
                            </template>

                            <li x-show="filteredBanks.length === 0" x-cloak>
                                <p class="px-4 py-3 text-sm text-gray-400">Không tìm thấy ngân hàng nào khớp.</p>
                            </li>

                            {{-- Lựa chọn CUỐI: thẻ thuộc ngân hàng chưa có trong danh sách.
                                 Lưu `bank_id = NULL`, KHÔNG tạo bank giả. --}}
                            <li role="option" class="border-t border-gray-100 mt-1 pt-1" :aria-selected="form.bank_id === '' ? 'true' : 'false'">
                                <button type="button" @click="pickBank('')" data-testid="bank-other-option"
                                        class="w-full min-h-12 px-4 py-2.5 text-left text-gray-500">
                                    Ngân hàng khác
                                </button>
                            </li>
                        </ul>
                    </div>

                    <p class="text-xs text-gray-500">
                        Gõ tên hoặc mã ngân hàng để tìm. Chọn <span class="font-medium">Ngân hàng khác</span>
                        nếu thẻ thuộc ngân hàng chưa có trong danh sách — bạn có thể cập nhật sau.
                    </p>
                </div>

                {{-- Kỳ sao kê: chọn ngày bắt đầu ⇒ ngày kết thúc tự tính --}}
                <fieldset class="space-y-1.5">
                    <legend class="text-sm font-semibold text-gray-700">Kỳ sao kê</legend>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <label for="cc-period-start" class="block text-xs text-gray-500">Ngày bắt đầu</label>
                            <input id="cc-period-start" type="date" x-model="form.statement_period_start" @change="onPeriodStartChange()"
                                   class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div class="space-y-1.5">
                            <span class="block text-xs text-gray-500">Ngày kết thúc (tự tính)</span>
                            {{-- readonly: server mới là nơi quyết định, xem header file. --}}
                            <input type="text" :value="periodEnd" readonly tabindex="-1"
                                   class="w-full h-12 rounded-xl border-gray-200 bg-gray-50 text-base px-4 text-gray-500">
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">
                        Kỳ sao kê dài đúng một tháng tính từ ngày bắt đầu.
                    </p>
                </fieldset>

                {{-- Ngày thanh toán + hạn chót giao dịch --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label for="cc-due-day" class="block text-sm font-semibold text-gray-700">Ngày thanh toán sao kê</label>
                        <input id="cc-due-day" type="number" inputmode="numeric" min="1" max="31" x-model="form.payment_due_day"
                               class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <p class="text-xs text-gray-500">Ngày trong tháng (1–31).</p>
                    </div>
                    <div class="space-y-1.5">
                        <label for="cc-deadline-day" class="block text-sm font-semibold text-gray-700">Hạn chót giao dịch</label>
                        <input id="cc-deadline-day" type="number" inputmode="numeric" min="1" max="31" x-model="form.spending_deadline_day"
                               class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <p class="text-xs text-gray-500">Thời điểm hạn chót chi tiêu để kịp lên giao dịch. Để trống nếu không cần thiết.</p>
                    </div>
                </div>

                {{-- Số tiền mong muốn chi tiêu --}}
                <div class="space-y-1.5">
                    <label for="cc-desired" class="block text-sm font-semibold text-gray-700">Số tiền mong muốn chi tiêu</label>
                    <input id="cc-desired" type="number" inputmode="decimal" min="0" step="100000" x-model="form.desired_spend"
                           placeholder="Ví dụ: 20000000"
                           class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <p class="text-xs text-gray-500">Mục tiêu cá nhân của bạn, khác với hạn mức thẻ.</p>
                </div>

                {{-- Khuyến mãi --}}
                <div class="space-y-1.5">
                    <label for="cc-promo" class="block text-sm font-semibold text-gray-700">Thông tin khuyến mãi</label>
                    <textarea id="cc-promo" rows="3" maxlength="2000" x-model="form.promotion_info"
                              placeholder="Ưu đãi, mã giảm giá, chương trình thưởng của thẻ…"
                              class="w-full rounded-xl border-gray-300 text-base px-4 py-3 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                </div>

                {{-- Ghi chú --}}
                <div class="space-y-1.5">
                    <label for="cc-note" class="block text-sm font-semibold text-gray-700">Ghi chú</label>
                    <textarea id="cc-note" rows="2" maxlength="1000" x-model="form.note"
                              class="w-full rounded-xl border-gray-300 text-base px-4 py-3 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                </div>
            </div>

            {{-- ═══ Chính sách hoàn tiền ═══ --}}
            <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-4">
                <div>
                    <h3 class="font-bold text-gray-800 text-base">🎯 Chính sách hoàn tiền</h3>
                    <p class="text-xs text-gray-500 mt-1">
                        Chọn mẫu hệ thống hoặc mẫu của bạn. Hệ thống <strong>sao chép</strong> thành bản
                        riêng cho thẻ này — mẫu gốc không bị thay đổi. Thẻ và chính sách được lưu
                        <strong>cùng một lần</strong>.
                    </p>
                </div>

                {{-- Chọn mẫu. Không cần lưu thẻ trước: mọi thứ đi trong payload của nút "Lưu thẻ". --}}
                <div class="space-y-1.5">
                    <label for="cc-template" class="block text-sm font-semibold text-gray-700">Chọn chính sách</label>
                    <select id="cc-template" x-model="policy.template_id" @change="loadDraftFromTemplate()"
                            data-testid="policy-template-select"
                            class="w-full h-12 rounded-xl border-gray-300 text-base px-3 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">— Không dùng chính sách —</option>
                        <template x-for="t in systemTemplates" :key="'s' + t.id">
                            <option :value="t.id" x-text="'🏦 ' + t.name"></option>
                        </template>
                        <template x-for="t in userTemplates" :key="'u' + t.id">
                            <option :value="t.id" x-text="'👤 ' + t.name"></option>
                        </template>
                    </select>
                    <p class="text-xs text-gray-500">
                        Đang áp dụng: <span class="font-medium text-gray-700" x-text="policyName || 'chưa có'"></span>
                    </p>
                </div>

                {{-- ═══ TÓM TẮT ═══
                     Đọc thẳng từ `policyEditor` — cùng state object mà ô nhập bên dưới
                     ghi vào. Không có bản sao riêng để lệch. --}}
                <template x-if="policyEditor">
                    <div class="rounded-xl border-2 border-emerald-200 bg-emerald-50/60 p-4 space-y-3 min-w-0"
                         data-testid="policy-summary">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-emerald-700">Tóm tắt</p>
                                <p class="text-sm font-bold text-gray-900 break-words" x-text="policyEditor.meta.name"></p>
                            </div>
                            <span class="shrink-0 rounded-full bg-white px-2 py-1 text-[11px] font-semibold text-emerald-700 border border-emerald-200"
                                  x-text="policyEditor.tiers.length + ' bậc'"></span>
                        </div>

                        <p class="text-xs text-gray-600" x-text="policySourceLabel()"></p>

                        <template x-for="(tier, i) in policyEditor.tiers" :key="'t' + i">
                            <div class="rounded-lg bg-white border border-gray-200 p-3 space-y-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-800 break-words" x-text="tier.name || ('Bậc ' + (i + 1))"></p>
                                    <p class="text-[11px] text-gray-500 break-words" x-text="tierRange(tier)"></p>
                                    <p class="text-[11px] text-gray-600 break-words" x-text="tierCapLine(tier)"></p>
                                </div>

                                <ul class="space-y-1">
                                    <template x-for="(rule, ri) in enabledRules(tier)" :key="'r' + ri">
                                        <li class="flex items-baseline justify-between gap-2 text-xs">
                                            <span class="min-w-0 break-words text-gray-700" x-text="ruleLabel(rule)"></span>
                                            <span class="shrink-0 text-right">
                                                <span class="font-semibold text-emerald-700" x-text="rulePercent(rule)"></span>
                                                <span class="block text-[10px] text-gray-500" x-text="ruleCapLine(rule)"></span>
                                            </span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- ═══ CHỈNH SỬA — CHÍNH POLICY EDITOR CHÍNH THỨC ═══
                     Ở đây KHÔNG dựng lại form bậc/rule: partial dưới đây là bản canonical
                     dùng chung với trang quản trị, nên form Thẻ và trang admin không
                     thể lệch nhau về bất biến target, ô cap hay cách gửi payload.

                     `hosted` ⇒ partial không sinh `x-data` và KHÔNG có nút lưu: nút
                     "Lưu thẻ" dính đáy form là nút lưu DUY NHẤT (một transaction
                     ghi cả thẻ lẫn chính sách). --}}
                <template x-if="policyEditor">
                    <div class="rounded-xl border border-gray-200 p-4 space-y-4 min-w-0" data-testid="policy-editor">
                        <div>
                            <p class="text-sm font-bold text-gray-800">✏️ Chỉnh sửa cấu hình</p>
                            <p class="text-xs text-gray-500 mt-1">
                                Sửa ở đây chỉ đổi bản riêng của thẻ này. Mẫu gốc giữ nguyên.
                            </p>
                        </div>

                        @include('credit-card.partials.policy-editor', [
                            'hosted' => true,
                            // Mọi binding của editor trỏ vào `policyEditor.*` trên
                            // component cha ⇒ tóm tắt và ô nhập dùng CHUNG một state.
                            'scopePrefix' => 'policyEditor.',
                            // "Mô tả"/"Trạng thái" thuộc TEMPLATE, policy riêng của
                            // thẻ không có hai field đó.
                            'showTemplateMeta' => false,
                        ])
                    </div>
                </template>
            </section>

            {{-- Thanh hành động dính đáy: nút lớn, chạm bằng ngón cái --}}
            <div class="sticky bottom-0 -mx-4 sm:mx-0 px-4 sm:px-0 py-3 bg-white/95 backdrop-blur border-t border-gray-100">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="closeForm()"
                            class="h-14 rounded-2xl border border-gray-300 text-base font-semibold text-gray-600">
                        Huỷ
                    </button>
                    <button type="submit" :disabled="busy"
                            class="h-14 rounded-2xl bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 disabled:opacity-50 text-white text-base font-bold transition-colors">
                        Lưu thẻ
                    </button>
                </div>
            </div>
        </form>
    </div>

    @once
        <script>
            /**
             * Bản sao của `UserCardService::statementPeriodEnd()` cho phần hiển thị
             * tức thì. Server vẫn là nơi quyết định giá trị lưu xuống — hàm này
             * chỉ để ô "Ngày kết thúc" đổi số ngay khi chọn ngày bắt đầu.
             *
             * Công thức: `end = start + 1 tháng − 1 ngày`, neo theo ngày bắt đầu.
             * Ngày bắt đầu rơi vào 29–31 mà tháng sau ngắn hơn thì neo vào NGÀY CUỐI
             * tháng sau, khớp với `addMonthNoOverflow()` + `endOfMonth()` bên PHP:
             *   01/01 → 31/01   15/01 → 14/02   30/01 → 27/02   31/12 → 30/01
             */
            function ccPeriodEnd(start) {
                if (!start) return '';

                const from = new Date(`${start}T00:00:00`);
                if (Number.isNaN(from.getTime())) return '';

                // `getMonth()` là 0-based ⇒ +1 là tháng kế tiếp. Với tháng 12,
                // `new Date(y, 12, 1)` tự lùi sang 01/01 của năm sau — đúng ý định.
                const next = new Date(from.getFullYear(), from.getMonth() + 1, 1);

                // Ngày cuối của tháng kế tiếp: ngày 0 của tháng SAU tháng kế tiếp.
                const lastDay = new Date(next.getFullYear(), next.getMonth() + 1, 0).getDate();
                const anchor = from.getDate() <= lastDay ? from.getDate() : lastDay;

                const end = new Date(next.getFullYear(), next.getMonth(), anchor);
                end.setDate(end.getDate() - 1);

                return localDate(end);
            }

            /**
             * Ngày hôm nay theo GIỜ ĐỊA PHƯƠNG, dạng `YYYY-MM-DD`.
             *
             * KHÔNG dùng `toISOString()`: nó đổi sang UTC nên với giờ VN (UTC+7),
             * từ 00:00–07:00 sẽ trả về HÔM QUA. Ngày sao kê lệch một ngày là sai.
             */
            function localToday() {
                const now = new Date();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');

                return `${now.getFullYear()}-${month}-${day}`;
            }

            /** `Date` → `YYYY-MM-DD` theo giờ địa phương (xem `localToday`). */
            function localDate(date) {
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');

                return `${date.getFullYear()}-${month}-${day}`;
            }

            /**
             * Form trống cho thẻ mới / sau khi đóng form.
             *
             * PHẢI là hàm đứng riêng, KHÔNG phải method của object component.
             * `x-data="creditCardManager({...})"` gọi hàm này như một hàm trần nên
             * `this` lúc đó KHÔNG phải component — `this.blankForm()` trong object
             * literal sẽ ném `TypeError` ngay lúc Alpine khởi tạo và làm chết toàn
             * bộ trang (kể cả nút "+ Thêm thẻ"). Xem `categories.blade.php` với
             * `form: { mode: 'create', ... }` — một literal, cũng không cần `this`.
             *
             * `open` phải có mặt: nếu thiếu thì `form.open` là `undefined`, nút
             * "+ Thêm thẻ" (`x-show="! form.open"`) luôn hiện còn form
             * (`x-show="form.open"`) không bao giờ mở. Giống cách `policy` khai.
             */
            function ccBlankCardForm() {
                return {
                    open: false,
                    id: null,
                    name: '',
                    bank_id: '',
                    statement_period_start: '',
                    statement_period_end: '',
                    payment_due_day: 25,
                    spending_deadline_day: '',
                    desired_spend: '',
                    promotion_info: '',
                    note: '',
                };
            }

            /**
             * Trạng thái policy rỗng cho form thêm/sửa thẻ.
             *
             * Ở đây KHÔNG chứa cấu hình bậc/rule: cấu hình nằm trong `policyEditor`
             * (state của Policy Editor chính thức) để tóm tắt và ô nhập dùng chung
             * một nguồn sự thật. Field ở đây chỉ là "người dùng đang chọn/bửa gì".
             *
             * PHẢI là hàm đứng riêng như `ccBlankCardForm()`: `x-data` gọi hàm này
             * như hàm trần nên `this` lúc đó không phải component.
             */
            function ccBlankPolicyDraft() {
                return {
                    template_id: '',
                    // Id version đang chạy trên server (chỉ để hiển thị).
                    current_version_id: null,
                    current_version_no: null,
                };
            }

            /**
             * Ô nhập để trống ⇒ KHÔNG GIỚI HẠN ⇒ gửi `null`, không gửi `0`/`''`.
             *
             * Rất dễ sai: `Number('')` là 0, nên trả `''` thành 0 sẽ tạo trần 0 đồ
             * nghĩa "không được hoàn gì". Vì vậy chỉ `null`/`undefined`/chuỗi rỗng mới
             * thành `null`; chuỗi không phải số thì `null` để server báo lỗi.
             */
            function optionalNumber(value) {
                if (value === null || value === undefined || value === '') return null;

                const number = Number(value);

                return Number.isFinite(number) ? number : null;
            }

            /** Số tiền kiểu Việt Nam. `maximumFractionDigits: 0` vì các trần đều tròn. */
            function ccMoney(value) {
                return Number(value ?? 0).toLocaleString('vi-VN', { maximumFractionDigits: 0 });
            }

            /** Tỷ lệ giữ tối đa 2 chữ số thập phân, bỏ số 0 vô nghĩa. */
            function ccNumber(value) {
                return Number(value ?? 0).toLocaleString('vi-VN', { maximumFractionDigits: 2 });
            }

            function creditCardManager(state) {
                return {
                    banks: state.banks,
                    cards: state.cards,
                    templates: state.templates,
                    // Danh sách target cho Policy Editor, render sẵn từ server.
                    ruleCategories: state.ruleCategories ?? [],
                    ruleCombos: state.ruleCombos ?? [],

                    busy: false,
                    error: '',
                    notice: '',

                    // Combo box ngân hàng: mở/đóng + từ khoá tìm kiếm.
                    bankPickerOpen: false,
                    bankQuery: '',

                    form: ccBlankCardForm(),

                    /**
                     * Cấu hình policy ĐANG SỬA trên máy người dùng — là state của
                     * Policy Editor chính thức (`policyEditorState()` trong partial dùng
                     * chung), KHÔNG phải một bản draft riêng của form thẻ.
                     *
                     * `null` = chưa có cấu hình nào để hiện; object = đang sửa. Cả ô nhập
                     * lẫn tóm tắt đều đọc/ghi CHÍNH object này nên tóm tắt không bao
                     * giờ hiện khác với thứ đang sửa.
                     */
                    policy: ccBlankPolicyDraft(),
                    policyEditor: null,

                    get systemTemplates() {
                        return this.templates.filter((t) => t.is_system);
                    },

                    get userTemplates() {
                        return this.templates.filter((t) => !t.is_system);
                    },

                    get policyName() {
                        return this.policyEditor ? this.policyEditor.meta.name : '';
                    },

                    /**
                     * Dòng giải thích nguồn của bản đang sửa, nói rõ đây là bản sao
                     * hay bản riêng — người dùng cần biết mình đang sửa cái gì trước khi
                     * bấm "Lưu thẻ".
                     */
                    policySourceLabel() {
                        if (this.policy.template_id) {
                            const template = this.templates.find((t) => Number(t.id) === Number(this.policy.template_id));

                            return template
                                ? `Sẽ sao chép từ mẫu ${template.is_system ? 'hệ thống' : 'của bạn'} “${template.name}” — mẫu gốc không bị đổi.`
                                : 'Sẽ sao chép từ mẫu đã chọn.';
                        }

                        if (this.policy.current_version_id) {
                            return `Đang sửa bản riêng của thẻ (version ${this.policy.current_version_no ?? 1}). Lưu sẽ tạo version mới, không ghi đè lịch sử.`;
                        }

                        return 'Cấu hình tự nhập. Lưu sẽ tạo chính sách riêng cho thẻ này.';
                    },

                    /**
                     * Ngày kết thúc hiển thị cho form đang mở.
                     * Cột `statement_period_end` đã lưu sẵn vẫn là giá trị chuẩn khi
                     * sửa thẻ cũ; chỉ khi đổi ngày bắt đầu mới tính lại.
                     */
                    get periodEnd() {
                        return this.form.statement_period_end || ccPeriodEnd(this.form.statement_period_start);
                    },

                    /**
                     * Từ khoá tìm ngân hàng đã lowercase + trim.
                     */
                    get bankKeyword() {
                        return (this.bankQuery || '').trim().toLowerCase();
                    },

                    /**
                     * Ngân hàng khớp từ khoá: theo TÊN, MÃ VIẾT TẮT, slug và alias.
                     *
                     * Không `LIKE` ở server vì đây là lọc tức thì trên tối đa 43 dòng
                     * đã render sẵn — làm trong bộ nhớ nhanh hơn và không round-trip.
                     * Nhờ vậy gõ mã "scb" vẫn ra Sacombank (mã nằm trong `aliases`).
                     */
                    get filteredBanks() {
                        const keyword = this.bankKeyword;

                        if (keyword === '') {
                            return this.banks;
                        }

                        return this.banks.filter((bank) => {
                            const haystacks = [
                                bank.name,
                                bank.short_name,
                                bank.slug,
                                ...(bank.aliases || []),
                            ];

                            return haystacks.some(
                                (value) => value != null && String(value).toLowerCase().includes(keyword)
                            );
                        });
                    },

                    /**
                     * Tên hiển thị trên ô đã chọn. `''` = chưa chọn ngân hàng, dùng
                     * chung cho cả "Chưa biết / Không chọn" lẫn "Ngân hàng khác".
                     */
                    get selectedBankLabel() {
                        if (this.form.bank_id === '' || this.form.bank_id === null) {
                            return 'Chưa biết / Không chọn';
                        }

                        const bank = this.banks.find((b) => b.id === this.form.bank_id);

                        return bank ? bank.name : 'Chưa biết / Không chọn';
                    },

                    toggleBankPicker() {
                        this.bankPickerOpen = !this.bankPickerOpen;

                        if (this.bankPickerOpen) {
                            // Mở là focus ngay ô tìm để gõ được bằng một tay.
                            this.$nextTick(() => this.$refs.bankSearch?.focus());
                        }
                    },

                    closeBankPicker() {
                        this.bankPickerOpen = false;
                        this.bankQuery = '';
                    },

                    /**
                     * Chọn ngân hàng. `null`/`''` nghĩa là chưa chọn — KHÔNG tạo bank giả,
                     * chỉ lưu `bank_id = NULL` (xem `save()` và `UserCardService`).
                     */
                    pickBank(bankId) {
                        const found = this.banks.find((b) => b.id === bankId);

                        this.form.bank_id = found ? found.id : '';
                        this.closeBankPicker();
                    },

                    openCreate() {
                        this.error = '';
                        this.notice = '';
                        this.closeBankPicker();
                        // `open: true` mới thực sự mở form — `x-show="form.open"`.
                        this.form = { ...ccBlankCardForm(), open: true };
                        this.policy = ccBlankPolicyDraft();
                        this.policyEditor = null;
                    },

                    openEdit(id) {
                        this.error = '';
                        this.notice = '';
                        this.closeBankPicker();

                        const card = this.cards.find((c) => String(c.id) === String(id));
                        if (!card) return;

                        this.form = {
                            open: true,
                            id: card.id,
                            name: card.name ?? '',
                            bank_id: card.bank?.id ?? '',
                            statement_period_start: card.statement_period_start ?? '',
                            statement_period_end: card.statement_period_end ?? '',
                            payment_due_day: card.payment_due_day ?? 25,
                            spending_deadline_day: card.spending_deadline_day ?? '',
                            desired_spend: card.desired_spend ?? '',
                            promotion_info: card.promotion_info ?? '',
                            note: card.note ?? '',
                        };

                        this.policy = ccBlankPolicyDraft();
                        this.policyEditor = null;

                        this.loadPolicy(card);
                    },

                    closeForm() {
                        this.form = ccBlankCardForm();
                        this.policy = ccBlankPolicyDraft();
                        this.policyEditor = null;
                        this.closeBankPicker();
                        this.error = '';
                        this.notice = '';
                    },

                    /** Ngày bắt đầu đổi ⇒ ngày kết thúc tính lại (bỏ giá trị đã lưu). */
                    onPeriodStartChange() {
                        this.form.statement_period_end = '';
                    },

                    cardBase(cardId) {
                        return `/thetindung/api/the/${cardId}/chinh-sach`;
                    },

                    async request(url, options = {}) {
                        this.busy = true;
                        this.error = '';

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
                                this.error = this.firstError(payload) || `Yêu cầu thất bại (${response.status}).`;
                                return null;
                            }

                            return payload;
                        } catch (e) {
                            this.error = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                            return null;
                        } finally {
                            this.busy = false;
                        }
                    },

                    firstError(payload) {
                        if (payload.message) return payload.message;
                        if (payload.errors) {
                            const first = Object.values(payload.errors)[0];
                            if (Array.isArray(first)) return first[0];
                        }
                        return null;
                    },

                    /**
                     * Sửa/thêm thẻ. `statement_period_end` KHÔNG gửi lên (server tự tính).
                     *
                     * `policy` đi TRONG payload này: server lưu thẻ + bản policy clone
                     * trong cùng một transaction, nên không thể có thẻ mà không có
                     * policy khi người dùng đã chọn.
                     */
                    async save() {
                        this.notice = '';

                        const body = {
                            name: this.form.name,
                            bank_id: this.form.bank_id === '' ? null : this.form.bank_id,
                            statement_period_start: this.form.statement_period_start || null,
                            payment_due_day: this.form.payment_due_day === '' ? null : this.form.payment_due_day,
                            spending_deadline_day: this.form.spending_deadline_day === '' ? null : this.form.spending_deadline_day,
                            desired_spend: this.form.desired_spend === '' ? null : this.form.desired_spend,
                            promotion_info: this.form.promotion_info || null,
                            note: this.form.note || null,
                        };

                        const policy = this.policyPayload();

                        if (policy) body.policy = policy;

                        const creating = !this.form.id;
                        const url = creating ? '/thetindung/api/the' : `/thetindung/api/the/${this.form.id}`;
                        const method = creating ? 'POST' : 'PATCH';

                        const payload = await this.request(url, { method, body: JSON.stringify(body) });
                        if (!payload) return;

                        const saved = payload.data;
                        this.form.id = saved.id;
                        // Giữ kiểu SỐ để khớp `:value="bank.id"` và so sánh nghiêm
                        // ngặt `form.bank_id === bank.id` (xem phần header). Nếu bỏ chọn
                        // ngân hàng thì phải về `''` để khớp ô "Chưa biết".
                        this.form.bank_id = saved.bank ? saved.bank.id : '';
                        this.form.statement_period_end = saved.statement_period_end ?? '';
                        this.notice = creating ? 'Đã thêm thẻ cùng chính sách.' : 'Đã lưu thẻ cùng chính sách.';

                        this.upsertCard(saved);

                        // Nạp lại policy ĐÃ LƯU từ server để draft khớp DB. Sau khi lưu
                        // không còn sửa tiếp được nữa (sẽ tạo version mới ở lần lưu kế).
                        await this.loadPolicy(saved);
                    },

                    /**
                     * Phần `policy` gửi kèm lúc lưu, hoặc `null` khi không đụng tới.
                     *
                     * `null` ≠ `{}` rỗng: gửi `{}` sẽ khiến server hiểu là "chủ động
                     * cập nhật policy" và tạo version mới vô ích.
                     *
                     * Cấu hình bậc/rule lấy THẲNG từ `policyEditor.versionConfig()` —
                     * cùng hàm dựng payload mà trang quản trị dùng. Nhờ vậy bất biến
                     * target (combo nhận diện bằng `combo_id`, fallback không mang id),
                     * ô trần "không giới hạn" ⇒ `null`, và các field không có ô nhập
                     * (`spend_from`/`spend_to`/`is_enabled`/`note`) được mang qua đều
                     * chỉ có MỘT nơi định nghĩa.
                     */
                    policyPayload() {
                        if (!this.policy.template_id && !this.policyEditor) return null;

                        const payload = this.policyEditor
                            ? {
                                name: this.policyEditor.meta.name,
                                ...this.policyEditor.versionConfig(),
                            }
                            : {
                                // Chưa có cấu hình để sửa: chỉ chọn mẫu, server tự clone
                                // nguyên blueprint.
                                effective_from: this.form.statement_period_start || localToday(),
                            };

                        if (this.policy.template_id) {
                            payload.template_id = Number(this.policy.template_id);
                        }

                        return payload;
                    },

                    upsertCard(saved) {
                        const index = this.cards.findIndex((c) => String(c.id) === String(saved.id));

                        const entry = { ...saved };
                        if (index >= 0) this.cards.splice(index, 1, entry);
                        else this.cards.push(entry);
                    },

                    /**
                     * Dựng state Policy Editor từ payload của mẫu HOẶC version.
                     *
                     * Gọi `window.policyEditorState()` — CHÍNH hàm của partial dùng
                     * chung — nên form thẻ và trang quản trị hydrate ra CÙNG một hình
                     * dạng state (kể cả `transaction_caps` và các field không có ô
                     * nhập). Trước đây mỗi màn có một hàm `toDraft()` riêng và bản của
                     * form thẻ làm rơi `transaction_caps` mỗi lần lưu lại.
                     */
                    mountPolicyEditor(source) {
                        this.policyEditor = window.policyEditorState(
                            {
                                name: source?.name ?? 'Chính sách của tôi',
                                description: source?.description ?? '',
                                // Mẫu không có ngày riêng ⇒ dùng ngày bắt đầu kỳ sao kê
                                // đang chọn, không chọn thì hôm nay (giống cũ).
                                effective_from: source?.effective_from
                                    || this.form.statement_period_start
                                    || localToday(),
                                status: source?.status ?? 'published',
                                tiers: source?.tiers ?? [],
                            },
                            this.ruleCategories,
                            this.ruleCombos,
                            false,
                        );
                    },

                    /**
                     * Nạp cấu hình riêng của thẻ thành state editor để sửa.
                     *
                     * Sửa thẻ KHÔNG clone lại từ mẫu: thẻ đang có bao nhiêu cấu hình
                     * thì lấy đúng bấy nhiêu (xem `CardPolicySaveService::applyPolicySelection`).
                     */
                    async loadPolicy(card) {
                        this.policyEditor = null;
                        this.policy.current_version_id = null;
                        this.policy.current_version_no = null;

                        const payload = await this.request(this.cardBase(card.id));
                        if (!payload) return;

                        const currentId = payload.meta?.current_version_id ?? null;
                        if (!currentId) return;

                        this.policy.current_version_id = currentId;

                        const detail = await this.request(`${this.cardBase(card.id)}/${currentId}`);
                        if (!detail?.data) return;

                        this.policy.current_version_no = detail.data.version_no ?? null;
                        this.mountPolicyEditor(detail.data);
                    },

                    /**
                     * Chọn mẫu ⇒ nạp blueprint về máy để sửa trước.
                     *
                     * CHƯA gọi API tạo policy. Trước đây bấm "Áp dụng" là ghi ngay
                     * vào DB, nên đóng tab giữa chừng là mất công và dễ để lại thẻ
                     * không có policy. Nay clone xảy ra trong lần bấm "Lưu thẻ".
                     */
                    async loadDraftFromTemplate() {
                        this.policyEditor = null;
                        this.policy.current_version_id = null;
                        this.policy.current_version_no = null;

                        if (!this.policy.template_id) {
                            // Bỏ chọn mẫu ⇒ quay lại cấu hình riêng đang có (nếu có).
                            if (this.form.id) this.loadPolicy({ id: this.form.id });
                            return;
                        }

                        const payload = await this.request(`/thetindung/api/mau-chinh-sach/${this.policy.template_id}`);
                        if (!payload) return;

                        this.mountPolicyEditor(payload.data);
                    },

                    enabledRules(tier) {
                        return (tier.rules ?? []).filter((rule) => rule.is_enabled !== false);
                    },

                    rulePercent(rule) {
                        return `${ccNumber(rule.cashback_percent)}%`;
                    },

                    /** Trần hoàn của bậc — dòng bắt buộc trong tóm tắt. */
                    tierCapLine(tier) {
                        const cap = optionalNumber(tier.max_cashback_per_period);

                        return cap === null
                            ? 'Tổng hoàn tối đa mỗi kỳ: không giới hạn'
                            : `Tổng hoàn tối đa mỗi kỳ: ${ccMoney(cap)}`;
                    },

                    /** Trần chi tiết của một quy tắc; gộp các trần còn lại. */
                    ruleCapLine(rule) {
                        const parts = [];

                        const perTx = optionalNumber(rule.max_cashback_per_transaction);
                        if (perTx !== null) parts.push(`tối đa ${ccMoney(perTx)}/giao dịch`);

                        const perPeriod = optionalNumber(rule.max_cashback_per_category_per_period);
                        if (perPeriod !== null) parts.push(`tối đa ${ccMoney(perPeriod)}/kỳ`);

                        return parts.length > 0 ? parts.join(' · ') : 'không giới hạn';
                    },

                    tierRange(tier) {
                        const min = Number(tier.min_total_spend ?? 0);
                        const max = tier.max_total_spend === null || tier.max_total_spend === undefined
                            ? 'không giới hạn'
                            : ccMoney(Number(tier.max_total_spend));

                        return `Chi tiêu từ ${ccMoney(min)} đến ${max} đ`;
                    },

                    /**
                     * Nhãn target của quy tắc trong tóm tắt.
                     *
                     * State của editor giữ `target_scope` (3 trạng thái) + id, KHÔNG giữ
                     * tên ⇒ nhãn lấy từ chính danh sách của editor. Target đã ẩn hiện
                     * rõ là "đã ẩn" thay vì mất tên, và `id` vẫn còn trong state nên
                     * lưu lại không làm mất cấu hình.
                     */
                    ruleLabel(rule) {
                        if (rule.target_scope === 'other') {
                            return '📦 Danh mục còn lại';
                        }

                        if (rule.target_scope === 'combo') {
                            return `🍱 ${this.policyEditor?.comboLabel(rule.combo_id) ?? 'Combo'}`;
                        }

                        return this.policyEditor?.categoryLabel(rule.category_id) ?? 'Danh mục';
                    },
                };
            }
        </script>
    @endonce
</x-credit-card.layout>
