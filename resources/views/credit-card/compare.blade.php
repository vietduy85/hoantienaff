{{--
    /thetindung/so-sanh — "Lựa chọn thẻ".

    Hai tab trong CÙNG một state Alpine `cardRecommendation`:
      - "Nhu cầu chi tiêu": nhập số tiền + danh mục chi tiêu dự kiến; gọi JSON
        `credit-cards.compare.recommend` để server mô phỏng trên engine cashback
        và trả hai nhóm "Thẻ của tôi" + "Thẻ trên thị trường".
      - "Tối ưu bộ thẻ": nhập chi tiêu theo DANH MỤC/tháng (nhiều dòng), chọn
        phạm vi + tiêu chí; gọi JSON `credit-cards.compare.optimize`
        (`PortfolioOptimizerService`) trả tối đa 3 phương án phân bổ thực khác.

    Cả hai CHỈ ĐỌC — không tạo kỳ sao kê, không ghi snapshot, không commit.
    Cấu trúc state `need` (tab 1) / `budget` (tab 2) là OBJECT để các component
    con (`x-credit-card.money-input`, `searchable-select`) ghi ngược vào đúng
    thuộc tính của state cha.
--}}
<x-credit-card.layout
    title="Lựa chọn thẻ"
    subtitle="Nhập nhu cầu chi tiêu để biết thẻ nào hoàn tiền nhiều nhất"
    active="compare">

    @php
        $scopes = \App\Services\CreditCard\Optimizer\OptimizerScope::labels();
        $criteria = \App\Services\CreditCard\Optimizer\OptimizationCriterion::labels();
    @endphp

    <div x-data="cardRecommendation(@js([
            'endpoint' => route('credit-cards.compare.recommend'),
            'optimizeEndpoint' => route('credit-cards.compare.optimize'),
            'scopes' => $scopes,
            'criteria' => $criteria,
        ]))"
         class="space-y-4 sm:space-y-5">

        {{-- ===== Thanh tab ===== --}}
        <div class="flex items-center gap-1 bg-white rounded-2xl shadow-sm border border-gray-100 p-1">
            <button type="button"
                    @click="tab = 'need'"
                    class="flex-1 h-10 rounded-xl text-sm font-semibold px-3 transition"
                    :class="tab === 'need'
                        ? 'bg-emerald-600 text-white shadow-sm'
                        : 'text-gray-600 hover:bg-gray-100'">
                ☝️ Nhu cầu chi tiêu
            </button>
            <button type="button"
                    @click="tab = 'optimize'"
                    class="flex-1 h-10 rounded-xl text-sm font-semibold px-3 transition"
                    :class="tab === 'optimize'
                        ? 'bg-emerald-600 text-white shadow-sm'
                        : 'text-gray-600 hover:bg-gray-100'">
                🎯 Tối ưu bộ thẻ
            </button>
        </div>

        <div x-show="tab === 'need'" x-cloak class="space-y-4 sm:space-y-5">

        {{-- ===== Tab 1 — Nhu cầu chi tiêu ===== --}}
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
                    <p class="text-xs text-gray-500"
                       x-show="! (result.my_cards.length && result.my_cards[0].low_rate_regime)">
                        Tiền hoàn thực tế tính theo quota còn lại của thẻ. Chỉ hiển thị bậc có tỷ lệ từ
                        2% trở lên; bậc chưa đạt được ghi rõ là tiềm năng, không phải khoản chắc chắn.
                    </p>
                    <p class="text-xs text-gray-500"
                       x-show="result.my_cards.length && result.my_cards[0].low_rate_regime"
                       x-cloak>
                        Không có bậc nào đạt trên 2% cho danh mục này. Danh sách chuyển sang chế độ tối ưu
                        mốc chi tiêu: hiển thị cả thẻ tỷ lệ thấp và ưu tiên thẻ mà khoản chi này được tính
                        vào mốc, giúp tiến gần bậc kế tiếp.
                    </p>

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
                                            <template x-if="row.needs_spend">
                                                <span class="ms-1 text-xs font-semibold text-sky-700">· giúp đạt mốc chi tiêu</span>
                                            </template>
                                        </p>
                                        <p class="text-xs text-gray-500 truncate">
                                            <span x-show="row.bank_name" x-text="row.bank_name"></span>
                                            <span x-show="row.tier_label" x-text="(row.bank_name ? ' · ' : '') + row.tier_label"></span>
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

                                {{-- "Nên chi tối đa": nhấn mạnh bằng khối lớn, đậm, có nền
                                     tương phản tốt; cảnh báo vượt mức tách riêng bên dưới. --}}
                                <div class="mt-3 rounded-xl border border-sky-200 bg-sky-50 p-3"
                                     x-show="row.eligible && row.max_spend && row.max_spend.amount"
                                     x-cloak>
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-700">
                                        Nên chi tối đa
                                    </p>
                                    <p class="text-2xl font-extrabold leading-tight text-sky-900 mt-0.5"
                                       x-text="ccMoneyVnd(row.max_spend.amount)"></p>
                                    <p class="text-sm font-semibold text-sky-800 mt-0.5"
                                       x-show="row.cashback_percent"
                                       x-text="'để nhận ' + ccNumber(row.cashback_percent) + '% hoàn tiền cho khoản này'"></p>
                                    <template x-if="row.max_spend && Number(row.max_spend.over_limit) > 0">
                                        <p class="text-sm font-semibold text-amber-800 mt-2 pt-2 border-t border-amber-200"
                                           x-text="'Cảnh báo: ' + ccMoneyVnd(row.max_spend.over_limit) + ' vượt mức sẽ không được hoàn.'"></p>
                                    </template>
                                </div>
                                <p class="text-xs text-amber-700 mt-1"
                                   x-show="row.eligible && row.max_spend && row.max_spend.reason === 'quota_exhausted'"
                                   x-cloak
                                   x-text="row.max_spend.message"></p>
                                <p class="text-xs text-gray-500 mt-1"
                                   x-show="row.eligible && row.max_spend && row.max_spend.amount === null && row.max_spend.reason !== 'quota_exhausted' && row.max_spend.message"
                                   x-cloak
                                   x-text="row.max_spend.message"></p>
                                <p class="text-xs text-sky-700 mt-1"
                                   x-show="row.spend_goal && row.spend_goal.status === 'short'"
                                   x-cloak
                                   x-text="row.spend_goal.message"></p>
                                <p class="text-xs text-gray-500 mt-1"
                                   x-show="row.spend_goal && row.spend_goal.status === 'not_counted'"
                                   x-cloak
                                   x-text="row.spend_goal.message"></p>

                                @include('credit-card.partials.compare-tiers')
                            </li>
                        </template>
                    </ul>
                </section>

                {{-- --- Thẻ trên thị trường --- --}}
                <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                    <h4 class="font-semibold text-gray-800 text-sm">🏦 Thẻ trên thị trường</h4>
                    <p class="text-xs text-gray-500">
                        Tiền hoàn tính độc lập cho từng bậc theo khoản chi bạn nhập; chỉ hiển thị bậc có tỷ lệ
                        từ 2% trở lên. Hạn mức hiển thị là giới hạn theo chính sách của thẻ — không phải quota
                        còn lại của bạn. Bậc chưa đạt được ghi rõ là tiềm năng.
                    </p>

                    <p class="text-sm text-gray-500" x-show="result.market_cards.length === 0" x-cloak>
                        Không có thẻ nào trong danh mục mẫu hoàn tiền cho nhu cầu này.
                    </p>

                    <ul class="space-y-2">
                        <template x-for="(row, index) in result.market_cards" :key="row.template_id">
                            <li class="rounded-xl border p-3 sm:p-4"
                                :class="index === 0 && row.applies && Number(row.cashback) > 0
                                    ? 'border-emerald-300 bg-emerald-50/60'
                                    : 'border-gray-100'">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800 truncate">
                                            <span x-text="row.name"></span>
                                            <template x-if="index === 0">
                                                <span class="ms-1 text-xs font-semibold text-emerald-700">· tỷ lệ cao nhất</span>
                                            </template>
                                        </p>
                                        <p class="text-xs text-gray-500 truncate" x-show="row.tier_label">
                                            <span x-text="row.tier_label"></span>
                                            <span x-show="row.applies" class="text-emerald-700 font-semibold"> · bậc đang áp dụng</span>
                                        </p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="font-bold text-emerald-700" x-text="ccMoneyVnd(row.cashback)"></p>
                                        <p class="text-xs text-gray-500" x-text="ccNumber(row.rate) + '% trên chi tiêu'"></p>
                                    </div>
                                </div>

                                {{-- Liệt kê các bậc đạt ngưỡng cho danh mục — không gộp;
                                     bậc đang áp dụng vs bậc tiềm năng được đánh dấu. --}}
                                @include('credit-card.partials.compare-tiers')

                                <p class="text-xs text-gray-400 mt-2" x-show="row.assumption" x-text="row.assumption"></p>
                                <p class="text-xs text-amber-700 mt-1"
                                   x-show="row.qualification_notice"
                                   x-text="row.qualification_notice"></p>
                            </li>
                        </template>
                    </ul>
                </section>
            </div>
        </template>

        </div>{{-- /tab need --}}

        <div x-show="tab === 'optimize'" x-cloak class="space-y-4 sm:space-y-5">

            {{-- ===== Tab 2 — Form chi tiêu theo danh mục ===== --}}
            <form class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4"
                  @submit.prevent="optimizeRun()">
                <div>
                    <h4 class="font-semibold text-gray-800 text-sm">Chi tiêu dự kiến theo danh mục</h4>
                    <p class="text-xs text-gray-500 mt-1">
                        Nhập số tiền bạn định chi cho từng danh mục trong một tháng. Hệ thống phân bổ
                        các danh mục vào bộ thẻ để hoàn tiền tốt nhất theo tiêu chí bạn chọn (có thể
                        tách một danh mục cho nhiều thẻ).
                    </p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-semibold text-gray-700">Phạm vi thẻ</label>
                        <div class="space-y-1.5">
                            <template x-for="(label, key) in scopes" :key="key">
                                <label class="flex items-start gap-2 text-sm text-gray-600 cursor-pointer">
                                    <input type="radio" name="optimize-scope" :value="key"
                                           x-model="budget.scope"
                                           class="mt-0.5 text-emerald-600 focus:ring-emerald-500 border-gray-300">
                                    <span>
                                        <span class="font-medium text-gray-700" x-text="label.split(':')[0]"></span>
                                        <template x-if="label.includes(':')">
                                            <span class="text-gray-500" x-text="': ' + label.split(':').slice(1).join(':')"></span>
                                        </template>
                                    </span>
                                </label>
                            </template>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label for="opt-criterion" class="block text-sm font-semibold text-gray-700">Tiêu chí tối ưu</label>
                        <select id="opt-criterion" x-model="budget.criterion"
                                class="w-full h-12 rounded-xl border-gray-300 text-sm px-3 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <template x-for="(label, key) in criteria" :key="key">
                                <option :value="key" x-text="label"></option>
                            </template>
                        </select>
                        <p class="text-xs text-gray-400" x-text="criteria[budget.criterion] ?? ''"></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label for="opt-max-copies" class="block text-sm font-semibold text-gray-700">
                            Số thẻ tối đa cho mỗi loại thẻ
                        </label>
                        <input id="opt-max-copies" type="number" min="1" max="{{ \App\Services\CreditCard\Optimizer\PortfolioOptimizerService::MAX_COPIES_LIMIT }}"
                               x-model.number="budget.max_copies"
                               class="w-full h-12 rounded-xl border-gray-300 text-sm px-3 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <p class="text-xs text-gray-400">
                            Cho phép dùng tối đa bao nhiêu thẻ cùng một sản phẩm trong cùng phương án
                            (1 = mỗi loại thẻ dùng một lần). Hệ thống chỉ dùng thêm bản khi thật sự tăng hoàn tiền.
                        </p>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between gap-3">
                        <label for="opt-filter" class="block text-sm font-semibold text-gray-700">Số tiền theo danh mục</label>
                        <input id="opt-filter" type="text" x-model="budget.filter"
                               placeholder="Lọc danh mục…"
                               class="w-48 h-9 rounded-lg border-gray-300 text-sm px-3 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <p class="text-xs text-gray-400 mt-1" x-text="coveredText()"></p>
                </div>

                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach ($categories as $category)
                        <li class="rounded-xl border border-gray-100 p-3 flex items-center justify-between gap-3"
                            x-show="! budget.filter || String(@js($category['label'])).toLowerCase().includes(budget.filter.toLowerCase())">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-700 text-sm truncate">{!! $category['label'] !!}</p>
                                <p class="text-xs text-gray-400" x-text="'Danh mục ' + @js($category['sublabel'])"></p>
                            </div>
                            <div class="w-40 shrink-0">
                                {{-- LƯU Ý: `expr` là thuộc tính của Blade component ⇒ chỉ `{{ }}` mới được
                                     biên dịch; `@js(...)` KHÔNG chạy trong attribute component và sẽ để lại
                                     biểu thức Alpine sai (`budget.amounts[@js(...)]`). Vì id danh mục là số
                                     nguyên nên nội suy trực tiếp là an toàn. --}}
                                <x-credit-card.money-input
                                    expr="budget.amounts[{{ $category['id'] }}]"
                                    example="2000000"
                                    class="w-full h-10 rounded-lg border-gray-300 text-sm px-3 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            :disabled="optimizeLoading"
                            class="inline-flex items-center gap-2 px-4 h-11 rounded-xl bg-emerald-600 text-white text-sm font-semibold shadow-sm hover:bg-emerald-700 disabled:opacity-60 disabled:cursor-not-allowed">
                        <span x-show="! optimizeLoading">Tối ưu bộ thẻ</span>
                        <span x-show="optimizeLoading" x-cloak>Đang tối ưu…</span>
                    </button>
                    <p class="text-xs text-gray-500">Chỉ mô phỏng — không mở thẻ, không ghi dữ liệu.</p>
                </div>

                <p class="text-sm text-red-600" x-show="optimizeError" x-cloak x-text="optimizeError"></p>
                <p class="text-xs text-red-600" x-show="optimizeFieldErrors.amounts" x-cloak x-text="optimizeFieldErrors.amounts"></p>
            </form>

            {{-- ===== Tab 2 — Chưa có kết quả ===== --}}
            <div x-show="! optimizeResult && ! optimizeLoading" x-cloak
                 class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <p class="text-sm text-gray-500">Nhập chi tiêu theo danh mục rồi bấm “Tối ưu bộ thẻ”.</p>
            </div>

            {{-- ===== Tab 2 — Kết quả tối ưu ===== --}}
            <template x-if="optimizeResult">
                <div class="space-y-4">
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <p class="text-sm text-gray-600">
                            Tổng chi
                            <span class="font-semibold text-gray-800" x-text="ccMoneyVnd(optimizeResult.total_input_number)"></span>
                            · tiêu chí
                            <span class="font-semibold text-gray-800" x-text="optimizeResult.criterion_label"></span>.
                        </p>
                        <p class="text-xs text-gray-500 mt-1"
                           x-show="optimizeResult.plan_count === 0">
                            Không tìm được phương án nào khả thi với dữ liệu hiện tại.
                        </p>
                        <p class="text-xs text-gray-500 mt-1"
                           x-show="optimizeResult.plan_count === 1">
                            Chỉ tìm được một phương án phân bổ khác biệt với dữ liệu hiện tại.
                        </p>
                        <p class="text-xs text-gray-500 mt-1"
                           x-show="optimizeResult.plan_count > 1"
                           x-text="optimizeResult.plans.length + ' / ' + optimizeResult.max_plans + ' phương án khác nhau được hiển thị.'"></p>
                        <p class="text-xs text-gray-400 mt-1">
                            Các phương án được coi là trùng (gộp lại) khi phân bổ danh mục↔thẻ giống hệt nhau, kể cả tỷ lệ chia tiền.
                        </p>
                    </div>

                    {{-- ===== Tab 2 — Tóm tắt nhu cầu chi tiêu tháng ===== --}}
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5"
                         x-show="optimizeResult.categories.length">
                        <p class="text-sm font-semibold text-gray-800">Tóm tắt nhu cầu chi tiêu tháng</p>
                        <dl class="mt-2 divide-y divide-gray-100">
                            <template x-for="cat in optimizeResult.categories" :key="cat.id">
                                <div class="flex items-center justify-between gap-3 py-1.5">
                                    <dt class="text-sm text-gray-600 truncate" x-text="cat.name"></dt>
                                    <dd class="text-sm font-medium text-gray-800 tabular-nums shrink-0" x-text="ccMoneyVnd(cat.amount)"></dd>
                                </div>
                            </template>
                        </dl>
                        <div class="mt-2 flex items-center justify-between gap-3 border-t border-gray-100 pt-2">
                            <span class="text-sm font-semibold text-gray-700">Tổng chi tiêu dự kiến</span>
                            <span class="text-sm font-bold text-gray-900 tabular-nums shrink-0"
                                  x-text="ccMoneyVnd(optimizeResult.total_input_number)"></span>
                        </div>
                        <p class="text-xs text-gray-400 mt-1"
                           x-text="optimizeResult.categories.length + ' danh mục có chi tiêu.'"></p>
                    </div>

                    <template x-for="plan in optimizeResult.plans" :key="plan.rank">
                        <section class="bg-white rounded-2xl shadow-sm border p-5 space-y-3"
                                 :class="plan.rank === 1 ? 'border-emerald-300 bg-emerald-50/40' : 'border-gray-100'">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-gray-800 text-sm">
                                        Phương án <span x-text="plan.rank"></span>
                                        <span class="text-xs font-medium text-gray-500" x-text="'· ' + plan.cards_count + ' thẻ'"></span>
                                        <span class="text-xs font-medium text-gray-500"
                                              x-show="plan.distinct_products < plan.cards_count"
                                              x-text="'· ' + plan.distinct_products + ' loại thẻ'"></span>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        Phân bổ các danh mục vào bộ thẻ; một danh mục có thể chia cho nhiều thẻ.
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="font-extrabold text-emerald-700" x-text="ccMoneyVnd(plan.total_cashback)"></p>
                                    <p class="text-xs text-gray-500" x-text="'hoàn ' + ccNumber(plan.total_rate) + '% tổng chi'"></p>
                                </div>
                            </div>

                            <ul class="space-y-2">
                                <template x-for="card in plan.cards" :key="card.key">
                                    <li class="rounded-xl border border-gray-100 bg-white p-3 sm:p-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="font-medium text-gray-800 text-sm truncate">
                                                    <span x-text="card.name"></span>
                                                    <span class="ms-1 text-xs font-semibold"
                                                          :class="card.is_owned ? 'text-sky-700' : 'text-violet-700'"
                                                          x-text="card.is_owned ? '(thẻ của bạn)' : '(thẻ mới)'"></span>
                                                </p>
                                                <p class="text-xs text-gray-500 truncate">
                                                    <span x-show="card.bank_name" x-text="card.bank_name"></span>
                                                    <span x-show="card.tier_label" x-text="(card.bank_name ? ' · ' : '') + card.tier_label"></span>
                                                </p>
                                            </div>
                                            <div class="text-right shrink-0">
                                                <p class="font-bold text-emerald-700 tabular-nums" x-text="ccMoneyVnd(card.total_cashback)"></p>
                                                <p class="text-xs text-gray-500" x-text="'hoàn ' + ccNumber(card.rate) + '%'"></p>
                                                <p class="text-xs text-gray-500">
                                                    phân bổ
                                                    <span class="tabular-nums" x-text="ccMoneyVnd(card.allocated_total)"></span>
                                                </p>
                                            </div>
                                        </div>

                                        <ul class="mt-2 space-y-1 border-t border-gray-100 pt-2">
                                            <template x-for="cat in card.categories" :key="cat.category_id">
                                                <li class="flex items-baseline justify-between gap-3 text-xs">
                                                    <span class="text-gray-600 truncate">
                                                        <span x-text="cat.category_name"></span>
                                                        <span class="text-gray-400" x-show="! cat.eligible"> · chưa xác định quyền lợi</span>
                                                    </span>
                                                    <span class="shrink-0 text-right">
                                                        <span class="tabular-nums text-gray-700" x-text="ccMoneyVnd(cat.amount)"></span>
                                                        <span class="ms-1 tabular-nums"
                                                              :class="Number(cat.cashback) > 0 ? 'font-semibold text-emerald-700' : 'text-gray-400'"
                                                              x-text="(Number(cat.cashback) > 0 ? '+' : '') + ccMoneyVnd(cat.cashback)"></span>
                                                    </span>
                                                </li>
                                            </template>
                                        </ul>

                                        <p class="text-xs text-gray-400 mt-2" x-show="card.scenario_reason" x-text="ccReasonLabel(card.scenario_reason)"></p>
                                        <p class="text-xs text-amber-700 mt-1" x-show="card.qualification_notice" x-text="card.qualification_notice"></p>
                                        <p class="text-xs text-gray-400 mt-1" x-show="card.market_assumption" x-text="card.market_assumption"></p>
                                    </li>
                                </template>
                            </ul>
                        </section>
                    </template>

                    {{-- ===== Tab 2 — Tổng kết và đánh giá phương án ===== --}}
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4"
                         x-show="optimizeResult.plans.length">
                        <p class="text-sm font-semibold text-gray-800">Tổng kết và đánh giá phương án</p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            {{-- A. Nhu cầu ban đầu --}}
                            <div class="rounded-xl border border-gray-100 p-3 sm:p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nhu cầu ban đầu</p>
                                <p class="mt-2 text-sm text-gray-600">
                                    Tổng chi tiêu:
                                    <span class="font-semibold text-gray-800 tabular-nums" x-text="ccMoneyVnd(optimizeResult.total_input_number)"></span>
                                </p>
                                <p class="text-sm text-gray-600"
                                   x-text="optimizeResult.categories.length + ' danh mục có chi tiêu.'"></p>
                                <ul class="mt-2 space-y-0.5">
                                    <template x-for="cat in optimizeResult.categories" :key="cat.id">
                                        <li class="flex items-baseline justify-between gap-3 text-xs">
                                            <span class="text-gray-500 truncate" x-text="cat.name"></span>
                                            <span class="text-gray-700 tabular-nums shrink-0" x-text="ccMoneyVnd(cat.amount)"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>

                            {{-- B. Phương án đề xuất --}}
                            <div class="rounded-xl border border-gray-100 p-3 sm:p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Phương án đề xuất
                                    <span x-text="proposed().rank ? '(phương án ' + proposed().rank + ')' : ''"></span>
                                </p>
                                <dl class="mt-2 space-y-1 text-sm text-gray-600">
                                    <div class="flex items-center justify-between gap-3">
                                        <dt>Số thẻ</dt>
                                        <dd class="font-medium text-gray-800" x-text="(proposed().cards_count ?? 0) + ' thẻ'"></dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <dt>Tổng tiền phân bổ</dt>
                                        <dd class="font-medium text-gray-800 tabular-nums" x-text="ccMoneyVnd(proposed().total_allocated)"></dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <dt>Tổng tiền hoàn dự kiến</dt>
                                        <dd class="font-semibold text-emerald-700 tabular-nums" x-text="ccMoneyVnd(proposed().total_cashback)"></dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <dt>Tỷ lệ hoàn hiệu dụng</dt>
                                        <dd class="font-medium text-gray-800" x-text="ccNumber(proposed().total_rate) + '%'"></dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <dt>Thẻ đang sở hữu được dùng</dt>
                                        <dd class="font-medium text-gray-800" x-text="(proposed().owned_cards ?? 0) + ' thẻ'"></dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <dt>Thẻ mới cần cân nhắc mở</dt>
                                        <dd class="font-medium text-gray-800" x-text="(proposed().new_cards ?? 0) + ' thẻ'"></dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <dt>Chi tiêu chưa chắc chắn quyền lợi</dt>
                                        <dd class="font-medium text-gray-800 tabular-nums"
                                            x-text="Number(proposed().uncertain_total) > 0 ? ccMoneyVnd(proposed().uncertain_total) : 'Không có'"></dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        {{-- C. Mục tiêu và mức độ đạt mục tiêu --}}
                        <div class="rounded-xl border border-gray-100 p-3 sm:p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Mục tiêu và mức độ đạt mục tiêu</p>
                            <p class="mt-2 text-sm text-gray-600">
                                Mục tiêu đã chọn:
                                <span class="font-medium text-gray-800" x-text="optimizeResult.criterion_label"></span>
                            </p>
                            <p class="text-sm text-gray-600">
                                Phương án đề xuất là phương án tốt nhất trong các phương án tìm được theo đúng tiêu chí này (kết quả của thuật toán tìm kiếm, không phải tối ưu toàn cục).
                            </p>
                            <ul class="mt-2 space-y-1">
                                <template x-for="(line, i) in assessmentLines()" :key="i">
                                    <li class="text-sm text-gray-700" x-text="'• ' + line"></li>
                                </template>
                            </ul>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-1"
                         x-show="optimizeResult.notes.length">
                        <p class="text-sm font-semibold text-gray-800">Lưu ý</p>
                        <template x-for="(note, i) in optimizeResult.notes" :key="i">
                            <p class="text-xs text-gray-500" x-text="'• ' + note"></p>
                        </template>
                    </div>
                </div>
            </template>

        </div>{{-- /tab optimize --}}

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
             * Nhãn trạng thái đủ điều kiện của một bậc. Bậc chưa đạt kèm số tiền còn
             * thiếu và luôn được nêu là TIỀM NĂNG, không phải khoản chắc chắn nhận.
             */
            function ccTierStatusLabel(status, shortfall) {
                if (status === 'applied') {
                    return 'Đang áp dụng cho khoản chi này.';
                }

                if (status === 'not_met') {
                    return Number(shortfall) > 0
                        ? 'Chưa đạt điều kiện — còn thiếu ' + ccMoneyVnd(shortfall) + ' chi tiêu nữa.'
                        : 'Chưa đạt điều kiện của bậc này.';
                }

                if (status === 'passed') {
                    return 'Khoản chi này đã vượt mốc của bậc.';
                }

                return 'Không xác định đủ điều kiện cho bậc này.';
            }

            /** Hạn mức chính sách của một bậc (loại + số tiền) để hiển thị. */
            function ccCapDetailsLabel(caps) {
                return (caps ?? [])
                    .map((cap) => ccCapsLabel([cap.type]) + ': ' + ccMoneyVnd(cap.limit))
                    .join(', ');
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
                    optimizeEndpoint: config.optimizeEndpoint,
                    scopes: config.scopes,
                    criteria: config.criteria,
                    tab: 'need',
                    need: { amount: '', category_id: '' },
                    loading: false,
                    error: '',
                    fieldErrors: { amount: '', category_id: '' },
                    result: null,
                    budget: { scope: 'compare', criterion: 'balance', amounts: {}, filter: '', max_copies: 1 },
                    optimizeLoading: false,
                    optimizeError: '',
                    optimizeFieldErrors: {},
                    optimizeResult: null,

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

                    /** Số danh mục đang nhập + tổng chi (dạng chuỗi ngắn). */
                    coveredText() {
                        let count = 0;
                        let total = 0;

                        for (const [categoryId, amount] of Object.entries(this.budget.amounts)) {
                            const value = Number(amount ?? 0);

                            if (value > 0) {
                                count++;
                                total += value;
                            }
                        }

                        if (count === 0) {
                            return 'Chưa có danh mục nào nhập số tiền.';
                        }

                        return count + ' danh mục · tổng ' + ccMoneyVnd(total);
                    },

                    /**
                     * Chạy tối ưu: gửi scope + criterion + amounts (chỉ giữ danh mục
                     * có số tiền > 0) lên `credit-cards.compare.optimize`.
                     */
                    async optimizeRun() {
                        this.optimizeError = '';
                        this.optimizeFieldErrors = {};

                        const amounts = {};

                        for (const [categoryId, amount] of Object.entries(this.budget.amounts)) {
                            const value = Number(amount ?? 0);

                            if (value > 0) {
                                amounts[categoryId] = value;
                            }
                        }

                        if (Object.keys(amounts).length === 0) {
                            this.optimizeFieldErrors = { amounts: 'Vui lòng nhập số tiền cho ít nhất một danh mục.' };

                            return;
                        }

                        this.optimizeLoading = true;

                        try {
                            const url = new URL(this.optimizeEndpoint, window.location.origin);
                            url.searchParams.set('scope', this.budget.scope);
                            url.searchParams.set('criterion', this.budget.criterion);
                            url.searchParams.set('max_copies_per_card', String(this.budget.max_copies || 1));

                            for (const [categoryId, amount] of Object.entries(amounts)) {
                                url.searchParams.set('amounts[' + categoryId + ']', String(amount));
                            }

                            const response = await fetch(url.toString(), {
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });

                            const payload = await response.json().catch(() => ({}));

                            if (! response.ok) {
                                this.optimizeError = payload?.errors
                                    ? (this.firstOf(payload.errors.amounts) || 'Vui lòng kiểm tra lại thông tin.')
                                    : 'Không tối ưu được. Vui lòng thử lại.';
                                this.optimizeFieldErrors = payload?.errors ?? {};

                                return;
                            }

                            this.optimizeResult = payload.data;
                        } catch (e) {
                            this.optimizeError = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                        } finally {
                            this.optimizeLoading = false;
                        }
                    },

                    firstOf(list) {
                        return Array.isArray(list) && list.length ? list[0] : '';
                    },

                    /** Phương án đang được đề xuất (hạng 1) — object rỗng khi chưa có. */
                    proposed() {
                        return (this.optimizeResult?.plans?.[0]) ?? {};
                    },

                    /** Mức hoàn cao nhất trong TẤT CẢ phương án tìm được (server trả về). */
                    bestCashback() {
                        return Number(this.optimizeResult?.best_cashback ?? 0);
                    },

                    /**
                     * Phương án dùng ÍT THẺ HƠN phương án đề xuất, nếu có trong tập
                     * đã trả về — dùng để nêu đánh đổi "ít thẻ hơn, hoàn thấp hơn".
                     */
                    fewerCardsAlternative() {
                        const plans = this.optimizeResult?.plans ?? [];
                        const chosen = plans[0];

                        if (! chosen) {
                            return null;
                        }

                        let result = null;

                        for (const plan of plans) {
                            if (plan.cards_count < chosen.cards_count
                                && (! result || plan.cards_count < result.cards_count)) {
                                result = plan;
                            }
                        }

                        return result;
                    },

                    /**
                     * Các kết luận ĐỊNH TÍNH (có giải thích, dựa trên số liệu thật đã
                     * trả về) — KHÔNG bịa điểm số phần trăm mục tiêu.
                     */
                    assessmentLines() {
                        const chosen = this.proposed();

                        if (! chosen.cards_count) {
                            return ['Chưa có phương án để đánh giá.'];
                        }

                        const lines = [];
                        const chosenCashback = Number(chosen.total_cashback);
                        const best = this.bestCashback();
                        const gap = best - chosenCashback;

                        if (gap > 0.5) {
                            lines.push('Tiền hoàn thấp hơn ' + ccMoneyVnd(gap) + ' so với phương án hoàn tối đa trong các phương án tìm được.');
                        } else {
                            lines.push('Đạt mức hoàn tiền cao nhất trong các phương án tìm được.');
                        }

                        const fewer = this.fewerCardsAlternative();

                        if (fewer) {
                            const diff = Number(fewer.total_cashback) - chosenCashback;

                            lines.push(diff < -0.5
                                ? 'Có phương án dùng ít hơn (' + fewer.cards_count + ' so với ' + chosen.cards_count + ' thẻ) nhưng tiền hoàn thấp hơn ' + ccMoneyVnd(Math.abs(diff)) + '.'
                                : 'Có phương án dùng ít hơn (' + fewer.cards_count + ' thẻ) với tiền hoàn không thấp hơn.');
                        } else if (chosen.cards_count <= 1) {
                            lines.push('Phương án đã dùng ít thẻ nhất có thể trong các phương án tìm được.');
                        } else {
                            lines.push('Không tìm được phương án dùng ít thẻ hơn so với phương án đề xuất.');
                        }

                        if (Number(chosen.new_cards) > 0) {
                            lines.push('Chưa đủ dữ liệu để xác nhận điều kiện ưu đãi của ' + chosen.new_cards + ' thẻ mới cần cân nhắc mở.');
                        }

                        return lines;
                    },
                };
            }
        </script>
    @endonce

</x-credit-card.layout>
