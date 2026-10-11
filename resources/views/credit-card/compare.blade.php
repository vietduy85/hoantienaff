{{--
    /thetindung/so-sanh — "Lựa chọn thẻ".

    Người dùng nhập số tiền + danh mục chi tiêu dự kiến; trang gọi JSON
    `credit-cards.compare.recommend` để server mô phỏng trên engine cashback và
    trả hai nhóm: "Thẻ của tôi" (kỳ hiện tại + giao dịch giả định) và "Thẻ trên
    thị trường" (template hệ thống). CHỈ ĐỌC — không tạo kỳ sao kê, không ghi
    snapshot; xem `App\Services\CreditCard\CardRecommendationService`.

    Cấu trúc state `need` là OBJECT (không phải scalar) để ô nhập tiền
    (`x-credit-card.money-input` dùng `expr="need.amount"`) và ô chọn danh mục
    (`searchable-select` entangle `x-model`) ghi ngược được vào state cha.
--}}
<x-credit-card.layout
    title="Lựa chọn thẻ"
    subtitle="Nhập nhu cầu chi tiêu để biết thẻ nào hoàn tiền nhiều nhất"
    active="compare">

    <div x-data="cardRecommendation(@js(['endpoint' => route('credit-cards.compare.recommend')]))"
         class="space-y-4 sm:space-y-5">

        {{-- ===== Nhu cầu chi tiêu ===== --}}
        <form class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4"
              @submit.prevent="run()">
            <div>
                <h4 class="font-semibold text-gray-800 text-sm">Nhu cầu chi tiêu</h4>
                <p class="text-xs text-gray-500 mt-1">
                    Nhập số tiền và danh mục bạn dự định chi — hệ thống ước tính tiền hoàn cho từng thẻ.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label for="rc-amount" class="block text-sm font-semibold text-gray-700">Số tiền dự kiến</label>
                    <x-credit-card.money-input
                        id="rc-amount"
                        expr="need.amount"
                        example="1555000"
                        class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="text-xs text-red-600" x-show="fieldErrors.amount" x-cloak x-text="fieldErrors.amount"></p>
                </div>

                <div class="space-y-1.5">
                    <label for="rc-category" class="block text-sm font-semibold text-gray-700">Danh mục chi tiêu</label>
                    <x-credit-card.searchable-select
                        id="rc-category"
                        x-model="need.category_id"
                        :options="$categories"
                        placeholder="— Chọn danh mục —"
                        search-placeholder="Tìm danh mục…"
                        empty-text="Không tìm thấy danh mục nào khớp."
                        class="w-full" />
                    <p class="text-xs text-red-600" x-show="fieldErrors.category_id" x-cloak x-text="fieldErrors.category_id"></p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        :disabled="loading"
                        class="inline-flex items-center gap-2 px-4 h-11 rounded-xl bg-emerald-600 text-white text-sm font-semibold shadow-sm hover:bg-emerald-700 disabled:opacity-60 disabled:cursor-not-allowed">
                    <span x-show="! loading">Gợi ý thẻ</span>
                    <span x-show="loading" x-cloak>Đang tính…</span>
                </button>
                <p class="text-xs text-gray-500">Chỉ mô phỏng — không thay đổi dữ liệu của bạn.</p>
            </div>

            <p class="text-sm text-red-600" x-show="error" x-cloak x-text="error"></p>
        </form>

        {{-- ===== Chưa có kết quả ===== --}}
        <div x-show="! result && ! loading" x-cloak
             class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <p class="text-sm text-gray-500">Nhập nhu cầu rồi bấm “Gợi ý thẻ” để xem đề xuất.</p>
        </div>

        {{-- ===== Kết quả ===== --}}
        <template x-if="result">
            <div class="space-y-4 sm:space-y-5">
                <p class="text-sm text-gray-600">
                    Với
                    <span class="font-semibold text-gray-800" x-text="ccMoneyVnd(result.scenario.amount)"></span>
                    ở danh mục
                    <span class="font-semibold text-gray-800" x-text="result.scenario.category_name ?? '—'"></span>:
                </p>

                {{-- --- Thẻ của tôi --- --}}
                <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                    <h4 class="font-semibold text-gray-800 text-sm">💳 Thẻ của tôi</h4>

                    <p class="text-sm text-gray-500" x-show="result.my_cards.length === 0" x-cloak>
                        Bạn chưa có thẻ nào đang dùng.
                    </p>

                    <ul class="space-y-2">
                        <template x-for="(row, index) in result.my_cards" :key="row.card_id">
                            <li class="rounded-xl border p-3 sm:p-4"
                                :class="index === 0 && row.eligible && Number(row.cashback) > 0
                                    ? 'border-emerald-300 bg-emerald-50/60'
                                    : 'border-gray-100'">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800 truncate">
                                            <span x-text="row.card_name"></span>
                                            <template x-if="index === 0 && row.eligible && Number(row.cashback) > 0">
                                                <span class="ms-1 text-xs font-semibold text-emerald-700">· tốt nhất</span>
                                            </template>
                                        </p>
                                        <p class="text-xs text-gray-500 truncate">
                                            <span x-show="row.bank_name" x-text="row.bank_name"></span>
                                            <span x-show="row.tier_name" x-text="(row.bank_name ? ' · ' : '') + 'Bậc ' + row.tier_name"></span>
                                        </p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="font-bold"
                                           :class="Number(row.cashback) > 0 ? 'text-emerald-700' : 'text-gray-400'"
                                           x-text="ccMoneyVnd(row.cashback)"></p>
                                        <p class="text-xs text-gray-500"
                                           x-show="Number(row.cashback) > 0"
                                           x-text="ccNumber(row.rate) + '% trên chi tiêu'"></p>
                                    </div>
                                </div>

                                <p class="text-xs text-amber-700 mt-2"
                                   x-show="! row.eligible"
                                   x-text="ccReasonLabel(row.reason)"></p>
                                <p class="text-xs text-amber-700 mt-2"
                                   x-show="row.eligible && row.caps_applied.length"
                                   x-text="'Đã chạm giới hạn: ' + ccCapsLabel(row.caps_applied)"></p>
                            </li>
                        </template>
                    </ul>
                </section>

                {{-- --- Thẻ trên thị trường --- --}}
                <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                    <h4 class="font-semibold text-gray-800 text-sm">🏦 Thẻ trên thị trường</h4>

                    <p class="text-sm text-gray-500" x-show="result.market_cards.length === 0" x-cloak>
                        Không có thẻ nào trong danh mục mẫu hoàn tiền cho nhu cầu này.
                    </p>

                    <ul class="space-y-2">
                        <template x-for="(row, index) in result.market_cards" :key="row.template_id">
                            <li class="rounded-xl border p-3 sm:p-4"
                                :class="index === 0 ? 'border-emerald-300 bg-emerald-50/60' : 'border-gray-100'">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800 truncate">
                                            <span x-text="row.name"></span>
                                            <template x-if="index === 0">
                                                <span class="ms-1 text-xs font-semibold text-emerald-700">· tỷ lệ cao nhất</span>
                                            </template>
                                        </p>
                                        <p class="text-xs text-gray-500 truncate" x-show="row.tier_name"
                                           x-text="'Bậc ' + row.tier_name"></p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="font-bold text-emerald-700" x-text="ccMoneyVnd(row.cashback)"></p>
                                        <p class="text-xs text-gray-500" x-text="ccNumber(row.rate) + '% trên chi tiêu'"></p>
                                    </div>
                                </div>
                                <p class="text-xs text-amber-700 mt-2"
                                   x-show="row.caps_applied.length"
                                   x-text="'Đã chạm giới hạn: ' + ccCapsLabel(row.caps_applied)"></p>
                            </li>
                        </template>
                    </ul>
                </section>
            </div>
        </template>

    </div>

    @once
        @include('credit-card.partials.money-js')

        <script>
            /**
             * Nhãn tiếng Việt cho lý do không hoàn tiền — cùng tập mã mà
             * `Transaction::REASON_*` / `CashbackCalculator` phát ra.
             */
            function ccReasonLabel(reason) {
                const labels = {
                    no_policy_version: 'Thẻ chưa gắn chính sách hoàn tiền hiệu lực.',
                    no_matching_tier: 'Chưa đạt bậc hoàn tiền nào của chính sách.',
                    qualification_not_met: 'Chưa đạt điều kiện hoàn tiền đặc biệt của kỳ.',
                    below_minimum_spend: 'Chưa đạt mức chi tiêu tối thiểu của chính sách.',
                    no_category_rule: 'Chính sách không hoàn tiền cho danh mục này.',
                    below_min_transaction_amount: 'Giao dịch dưới mức tối thiểu của quy tắc.',
                    no_category: 'Giao dịch chưa có danh mục.',
                };

                return labels[reason] ?? (reason ?? '');
            }

            /** Nhãn loại cap đã chạm (engine phát ra mã `*_transaction`…). */
            function ccCapsLabel(caps) {
                const labels = {
                    per_transaction: 'giới hạn mỗi giao dịch',
                    per_category: 'giới hạn mỗi danh mục',
                    per_period_total: 'giới hạn cả kỳ',
                };

                return (caps ?? []).map((cap) => labels[cap] ?? cap).join(', ');
            }

            /**
             * State trang "Lựa chọn thẻ" — CHỈ giữ nhu cầu + kết quả, mọi phép
             * tính tiền nằm ở server (`CardRecommendationService`).
             *
             * `need` là object để component con (`money-input`, `searchable-select`)
             * ghi ngược vào đúng thuộc tính của state cha.
             */
            function cardRecommendation(config) {
                return {
                    endpoint: config.endpoint,
                    need: { amount: '', category_id: '' },
                    loading: false,
                    error: '',
                    fieldErrors: { amount: '', category_id: '' },
                    result: null,

                    async run() {
                        this.error = '';
                        this.fieldErrors = { amount: '', category_id: '' };

                        if (! (Number(this.need.amount) > 0)) {
                            this.fieldErrors.amount = 'Vui lòng nhập số tiền lớn hơn 0.';

                            return;
                        }

                        if (this.need.category_id === '' || this.need.category_id === null) {
                            this.fieldErrors.category_id = 'Vui lòng chọn danh mục chi tiêu.';

                            return;
                        }

                        this.loading = true;

                        try {
                            const url = new URL(this.endpoint, window.location.origin);
                            url.searchParams.set('amount', this.need.amount);
                            url.searchParams.set('category_id', this.need.category_id);

                            const response = await fetch(url.toString(), {
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });

                            const payload = await response.json().catch(() => ({}));

                            if (! response.ok) {
                                if (payload?.errors) {
                                    this.fieldErrors = {
                                        amount: this.firstOf(payload.errors.amount),
                                        category_id: this.firstOf(payload.errors.category_id),
                                    };
                                    this.error = 'Vui lòng kiểm tra lại các ô được đánh dấu.';
                                } else {
                                    this.error = 'Không tính được đề xuất. Vui lòng thử lại.';
                                }

                                return;
                            }

                            this.result = payload.data;
                        } catch (e) {
                            this.error = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                        } finally {
                            this.loading = false;
                        }
                    },

                    firstOf(list) {
                        return Array.isArray(list) && list.length ? list[0] : '';
                    },
                };
            }
        </script>
    @endonce

</x-credit-card.layout>
