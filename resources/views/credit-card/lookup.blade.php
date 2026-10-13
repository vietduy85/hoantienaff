{{--
    /thetindung/tra-cuu — "Tra cứu".

    Hai chế độ (tab):
      1. Tra cứu thẻ   — chọn chính sách hoàn tiền đang có trong hệ thống rồi xem
         tóm tắt + danh sách MCC đã mapping với chính sách đó.
      2. Tra cứu MCC   — nhập mã 4 ký tự để xem mô tả và các chính sách liên quan.

    CHỈ ĐỌC: mọi dữ liệu đến từ `CardPolicyLookupService`; trang không tạo kỳ sao
    kê, không ghi snapshot, không đụng engine cashback của "Lựa chọn thẻ".

    Danh sách chính sách render SẴN (server) để mở form nhanh trên mạng yếu —
    đúng ưu tiên mobile; chi tiết tải qua JSON cùng origin.
--}}
<x-credit-card.layout
    title="Tra cứu"
    subtitle="Tra cứu chính sách hoàn tiền và mã MCC (Merchant Category Code)"
    active="lookup">

    <div x-data="cardLookup(@js(['policyEndpoint' => route('credit-cards.lookup.policy'), 'mccEndpoint' => route('credit-cards.lookup.mcc')]))"
         class="space-y-4 sm:space-y-5">

        {{-- ===== Chuyển chế độ ===== --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-2 flex gap-1.5"
             role="tablist" aria-label="Chế độ tra cứu">
            <button type="button"
                    role="tab"
                    data-testid="cc-lookup-tab-policy"
                    @click="tab = 'policy'"
                    :aria-selected="tab === 'policy' ? 'true' : 'false'"
                    class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 h-11 rounded-xl text-sm font-semibold transition-colors"
                    :class="tab === 'policy' ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-50 text-gray-600 hover:bg-gray-100'">
                <span aria-hidden="true">💳</span>
                <span>Tra cứu thẻ</span>
            </button>
            <button type="button"
                    role="tab"
                    data-testid="cc-lookup-tab-mcc"
                    @click="tab = 'mcc'"
                    :aria-selected="tab === 'mcc' ? 'true' : 'false'"
                    class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 h-11 rounded-xl text-sm font-semibold transition-colors"
                    :class="tab === 'mcc' ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-50 text-gray-600 hover:bg-gray-100'">
                <span aria-hidden="true">🔢</span>
                <span>Tra cứu MCC</span>
            </button>
        </div>

        {{-- ================================================================= --}}
        {{-- TAB 1 — TRA CỨU THẺ =====}}
        {{-- ================================================================= --}}
        <section x-show="tab === 'policy'" class="space-y-4 sm:space-y-5">
            <form class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4"
                  @submit.prevent="runPolicy()">
                <div>
                    <h3 class="font-semibold text-gray-800 text-sm">Chọn thẻ</h3>
                </div>

                <div class="space-y-1.5">
                    <x-credit-card.searchable-select
                        id="cc-lookup-policy"
                        testid="cc-lookup-policy-select"
                        x-model="policyId"
                        :options="$policyTemplates"
                        placeholder="— Chọn thẻ —"
                        search-placeholder="Tìm theo tên thẻ…"
                        empty-text="Không tìm thấy thẻ nào khớp." />
                    <p class="text-xs text-gray-500" x-show="{{ count($policyTemplates) === 0 ? 'true' : 'false' }}" x-cloak>
                        Chưa có thẻ nào trong hệ thống.
                    </p>
                    <p class="text-xs text-red-600" x-show="policyError" x-cloak x-text="policyError"></p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button"
                            data-testid="cc-lookup-policy-submit"
                            @click="runPolicy()"
                            :disabled="policyLoading"
                            class="inline-flex items-center gap-2 px-4 h-11 rounded-xl bg-emerald-600 text-white text-sm font-semibold shadow-sm hover:bg-emerald-700 disabled:opacity-60 disabled:cursor-not-allowed">
                        <span x-show="! policyLoading">Xem thẻ</span>
                        <span x-show="policyLoading" x-cloak>Đang tải…</span>
                    </button>
                    <p class="text-xs text-gray-500">Chỉ tra cứu — không thay đổi dữ liệu.</p>
                </div>
            </form>

            <p class="text-sm text-red-600" x-show="policyFetchError" x-cloak x-text="policyFetchError"></p>

            {{-- Kết quả tóm tắt chính sách --}}
            <template x-if="policyResult">
                <div data-testid="cc-lookup-policy-result" class="space-y-4 sm:space-y-5">
                    <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="font-bold text-gray-800 text-base" x-text="policyResult.policy.name"></h3>
                                <p class="text-xs text-gray-500 mt-0.5" x-show="policyResult.policy.description" x-text="policyResult.policy.description"></p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                                  :class="policyResult.policy.scope === 'system' ? 'bg-gray-100 text-gray-600' : 'bg-blue-50 text-blue-700'"
                                  x-text="policyResult.policy.scope === 'system' ? 'Hệ thống' : 'Của tôi'"></span>
                        </div>

                        <template x-if="! policyResult.policy.has_blueprint">
                            <p class="text-sm text-amber-700 rounded-xl bg-amber-50 border border-amber-200 p-3">
                                Chính sách này chưa có cấu hình bậc hoàn tiền trong hệ thống.
                            </p>
                        </template>

                        <template x-if="policyResult.policy.has_blueprint">
                            <div class="space-y-3">
                                <dl class="grid grid-cols-2 gap-3">
                                    <div class="rounded-xl bg-gray-50 p-3">
                                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Chi tiêu tối thiểu</dt>
                                        <dd class="text-sm font-semibold text-gray-800 mt-0.5" x-text="ccMoneyVnd(policyResult.policy.min_total_spend)"></dd>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3">
                                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Trần hoàn tiền mỗi kỳ</dt>
                                        <dd class="text-sm font-semibold text-gray-800 mt-0.5"
                                            x-text="policyResult.policy.max_cashback_total_per_period ? ccMoneyVnd(policyResult.policy.max_cashback_total_per_period) : 'Không giới hạn'"></dd>
                                    </div>
                                </dl>
                                <p class="text-xs text-gray-500" x-show="policyResult.policy.note" x-text="policyResult.policy.note"></p>

                                <div class="space-y-3">
                                    <template x-for="(tier, ti) in policyResult.policy.tiers" :key="ti">
                                        <div class="rounded-xl border border-gray-100 overflow-hidden">
                                            <div class="bg-gray-50 px-4 py-2.5 flex items-center justify-between gap-2">
                                                <span class="text-sm font-semibold text-gray-800" x-text="tier.name"></span>
                                                <span class="text-xs text-gray-500" x-text="ccTierRange(tier.min_total_spend, tier.max_total_spend)"></span>
                                            </div>
                                            <ul class="divide-y divide-gray-50">
                                                <template x-for="(rule, ri) in tier.rules" :key="ri">
                                                    <li class="px-4 py-2.5 flex items-start justify-between gap-3">
                                                        <div class="min-w-0">
                                                            <p class="text-sm text-gray-800" x-text="rule.name"></p>
                                                            <p class="text-[11px] text-gray-500 mt-0.5" x-show="rule.category_note || rule.note" x-text="rule.note"></p>
                                                        </div>
                                                        <div class="text-right shrink-0">
                                                            <p class="text-sm font-semibold text-emerald-700" x-text="ccNumber(rule.cashback_percent) + '%'"></p>
                                                            <p class="text-[11px] text-gray-400"
                                                               x-show="rule.max_cashback_per_transaction || rule.max_cashback_per_category_per_period"
                                                               x-text="ccRuleCaps(rule)"></p>
                                                        </div>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </section>

                    {{-- Hồ sơ MCC admin nhập (module "Quản lý MCC").
                         Cỡ chữ trong khu vực này dùng class lõi Tailwind đã có trong build:
                         tiêu đề section `text-lg` (18px), tên hồ sơ `text-base` (16px),
                         tiêu đề nhóm & nội dung `text-sm` (14px). KHÔNG dùng giá trị
                         arbitrary (text-[17px]...) vì chưa có trong CSS đã build. --}}
                    <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                        <h4 class="font-semibold text-gray-800 text-lg leading-snug">
                            Hồ sơ MCC chính sách
                            <span class="ml-1 font-medium text-gray-400" x-text="'(' + ((policyResult.mcc_profiles || []).length) + ')'"></span>
                        </h4>

                        <p class="text-sm text-gray-500 leading-relaxed" x-show="!((policyResult.mcc_profiles || []).length)" x-cloak>
                            Chưa có dữ liệu MCC cho chính sách này.
                        </p>

                        <p class="text-sm text-gray-400 leading-relaxed"
                           x-show="(policyResult.mcc_profiles || []).length && ccTotalDocuments(policyResult.mcc_profiles || []) === 0"
                           x-cloak>
                            Chưa có tài liệu tham khảo.
                        </p>

                        <ul class="space-y-3">
                            <template x-for="profile in (policyResult.mcc_profiles || [])" :key="profile.id">
                                <li class="rounded-xl border border-gray-100 p-3 sm:p-4 space-y-3">
                                    <p class="font-medium text-gray-800 text-base leading-snug" x-text="profile.name"></p>
                                    <p class="text-sm text-gray-500 leading-relaxed" x-show="profile.note" x-text="profile.note"></p>

                                    {{-- Phí thường niên: LUÔN hiển thị với mọi hồ sơ. Trường chưa có
                                         dữ liệu (NULL) hiển thị "Chưa cập nhật" — KHÔNG hiển thị 0 đ và
                                         không suy đoán. Mỗi hồ sơ chỉ đọc dữ liệu phí CỦA CHÍNH NÓ. --}}
                                    <div>
                                        <p class="text-sm font-semibold text-gray-600 mb-1">Phí thường niên</p>
                                        <ul class="text-sm leading-relaxed text-gray-600 space-y-1">
                                            <li>
                                                Thẻ chính:
                                                <span x-show="ccProfileFeeValue(profile.annual_fee_primary)" class="font-semibold text-gray-800" x-text="ccMoneyVnd(profile.annual_fee_primary) + '/năm'"></span>
                                                <span x-show="!ccProfileFeeValue(profile.annual_fee_primary)" class="italic text-gray-400">Chưa cập nhật</span>
                                            </li>
                                            <li>
                                                Thẻ phụ:
                                                <span x-show="ccProfileFeeValue(profile.annual_fee_supplementary)" class="font-semibold text-gray-800" x-text="ccMoneyVnd(profile.annual_fee_supplementary) + '/năm'"></span>
                                                <span x-show="!ccProfileFeeValue(profile.annual_fee_supplementary)" class="italic text-gray-400">Chưa cập nhật</span>
                                            </li>
                                            <li>
                                                Chi tiêu trong năm để được hoàn/miễn phí:
                                                <span x-show="ccProfileFeeValue(profile.annual_spend_for_annual_fee_waiver)" class="font-semibold text-gray-800" x-text="ccMoneyVnd(profile.annual_spend_for_annual_fee_waiver) + '/năm'"></span>
                                                <span x-show="!ccProfileFeeValue(profile.annual_spend_for_annual_fee_waiver)" class="italic text-gray-400">Chưa cập nhật</span>
                                            </li>
                                        </ul>
                                        <p class="mt-1 text-sm leading-relaxed text-gray-400" x-show="ccProfileHasFees(profile)">Theo tài liệu ngân hàng; điều kiện miễn phí có thể kèm điều kiện khác.</p>
                                    </div>

                                    <div x-show="(profile.included || []).length">
                                        <p class="text-sm font-semibold text-emerald-700 mb-1">MCC được hoàn tiền</p>
                                        <template x-for="(row, ri) in (profile.included || [])" :key="'in' + ri">
                                            <div class="text-sm leading-relaxed text-gray-600">
                                                <span class="font-medium text-gray-700" x-show="row.category_name" x-text="row.category_name + ': '"></span>
                                                <span class="font-mono text-emerald-700" x-text="(row.codes || []).join(', ')"></span>
                                                <span class="block text-gray-400" x-show="row.description || row.note" x-text="row.description || row.note"></span>
                                            </div>
                                        </template>
                                    </div>

                                    <div x-show="(profile.excluded || []).length">
                                        <p class="text-sm font-semibold text-rose-700 mb-1">MCC loại trừ</p>
                                        <template x-for="(row, ri) in (profile.excluded || [])" :key="'ex' + ri">
                                            <div class="text-sm leading-relaxed text-gray-600">
                                                <span class="font-medium text-gray-700" x-show="row.category_name" x-text="row.category_name + ': '"></span>
                                                <span class="font-mono text-rose-700" x-text="(row.codes || []).join(', ')"></span>
                                                <span class="block text-gray-400" x-show="row.description || row.note" x-text="row.description || row.note"></span>
                                            </div>
                                        </template>
                                    </div>

                                    <div x-show="(profile.documents || []).length">
                                        <p class="text-sm font-semibold text-gray-500 mb-1">Tài liệu tham khảo</p>
                                        <ul class="space-y-1.5">
                                            <template x-for="doc in (profile.documents || [])" :key="doc.id">
                                                <li class="flex items-center justify-between gap-2 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                                                    <div class="min-w-0">
                                                        <p class="truncate text-sm text-gray-700" x-text="doc.name"></p>
                                                        <p class="text-sm text-gray-400" x-text="doc.human_size"></p>
                                                    </div>
                                                    <a :href="doc.url"
                                                       rel="noreferrer"
                                                       class="shrink-0 text-sm font-medium text-emerald-700 hover:text-emerald-800">Tải xuống</a>
                                                </li>
                                            </template>
                                        </ul>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </section>
                </div>
            </template>
        </section>

        {{-- ===== Tra cứu MCC ===== --}}
        <section x-show="tab === 'mcc'" x-cloak class="space-y-4 sm:space-y-5">
            <form class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4"
                  @submit.prevent="runMcc()">
                <div>
                    <label for="cc-lookup-mcc" class="block text-sm font-semibold text-gray-700">Mã MCC (4 chữ số)</label>
                    <div class="mt-1.5 flex items-stretch gap-2">
                        <input id="cc-lookup-mcc"
                               data-testid="cc-lookup-mcc-input"
                               type="text"
                               inputmode="numeric"
                               autocomplete="off"
                               maxlength="4"
                               x-model="mccCode"
                               @input="mccCode = String(mccCode).replace(/\D/g, '').slice(0, 4)"
                               placeholder="Ví dụ: 5411"
                               class="flex-1 min-h-11 rounded-xl border-gray-300 font-mono text-base px-3 tracking-widest shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <button type="button"
                                data-testid="cc-lookup-mcc-submit"
                                @click="runMcc()"
                                :disabled="mccLoading"
                                class="inline-flex items-center gap-2 px-4 rounded-xl bg-emerald-600 text-white text-sm font-semibold shadow-sm hover:bg-emerald-700 disabled:opacity-60 disabled:cursor-not-allowed">
                            <span x-show="! mccLoading">Tra cứu</span>
                            <span x-show="mccLoading" x-cloak>Đang tra…</span>
                        </button>
                    </div>
                    <p class="text-xs text-red-600 mt-1" x-show="mccError" x-cloak x-text="mccError"></p>
                </div>
            </form>

            <p class="text-sm text-red-600" x-show="mccFetchError" x-cloak x-text="mccFetchError"></p>

            <template x-if="mccResult">
                <div data-testid="cc-lookup-mcc-result" class="space-y-4 sm:space-y-5">
                    <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="font-bold text-gray-800 text-lg font-mono tracking-widest" x-text="mccResult.code"></h3>
                            <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                                  :class="mccResult.known ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
                                  x-text="mccResult.known ? 'Có trong dữ liệu' : 'Không có trong dữ liệu'"></span>
                        </div>

                        <template x-if="mccResult.master">
                            <div class="space-y-1.5">
                                <p class="text-sm text-gray-800" x-show="mccResult.master.description_vi" x-text="mccResult.master.description_vi"></p>
                                <p class="text-sm text-gray-500" x-show="mccResult.master.description_en" x-text="mccResult.master.description_en"></p>
                                <p class="text-[11px] text-gray-400"
                                   x-show="mccResult.master.source"
                                   x-text="'Nguồn: ' + mccResult.master.source + ' · ' + ccVerificationLabel(mccResult.master.verification_status)"></p>
                            </div>
                        </template>

                        <p class="text-sm text-amber-700 rounded-xl bg-amber-50 border border-amber-200 p-3" x-show="! mccResult.known" x-cloak>
                            Không tìm thấy MCC này trong dữ liệu hệ thống.
                        </p>

                        <p class="text-xs text-amber-700" x-show="mccResult.note" x-cloak x-text="mccResult.note"></p>
                    </section>

                    <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                        <h4 class="font-semibold text-gray-800 text-sm">
                            Chính sách liên quan
                            <span class="ml-1 font-medium text-gray-400" x-text="'(' + mccResult.matches.length + ')'"></span>
                        </h4>

                        <p class="text-sm text-gray-500" x-show="mccResult.matches.length === 0" x-cloak>
                            Không có chính sách nào quy định hoàn tiền cho MCC này trong dữ liệu đã nhập.
                        </p>

                        <ul class="space-y-2">
                            <template x-for="(row, index) in mccResult.matches" :key="row.id">
                                <li class="rounded-xl border border-gray-100 p-3 sm:p-4 space-y-2">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="font-medium text-gray-800 truncate" x-text="row.policy_name"></p>
                                            <p class="mt-1">
                                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                                      :class="ccRelationClass(row.relation)"
                                                      x-text="row.relation_label"></span>
                                            </p>
                                        </div>
                                    </div>

                                    {{-- Tóm tắt hoàn tiền lấy từ blueprint: bậc + tỷ lệ + điều kiện + hạn mức. --}}
                                    <div class="rounded-xl border p-3" :class="ccCashbackBoxClass(row.cashback)">
                                        <template x-if="row.cashback && row.cashback.status === 'excluded'">
                                            <div>
                                                <p class="text-sm font-semibold text-rose-700">Không được hoàn tiền — MCC bị loại trừ</p>
                                                <p class="text-xs text-rose-600 mt-0.5" x-show="row.note" x-text="row.note"></p>
                                            </div>
                                        </template>

                                        <template x-if="row.cashback && row.cashback.status === 'available'">
                                            <div class="space-y-2">
                                                <p class="text-xs text-gray-500">
                                                    Danh mục:
                                                    <span class="font-semibold text-gray-800" x-text="row.cashback.category_name || row.category_name || '—'"></span>
                                                </p>

                                                {{-- Tỷ lệ hoàn tiền CAO NHẤT + điều kiện của nó (trích tài liệu gốc). --}}
                                                <template x-if="row.cashback.highest">
                                                    <div class="rounded-lg border border-emerald-100 bg-emerald-50/70 px-3 py-2">
                                                        <div class="flex items-start justify-between gap-3">
                                                            <p class="text-sm font-bold text-emerald-700">
                                                                Hoàn tối đa
                                                                <span x-text="ccBenefitRate(row.cashback.highest)"></span>
                                                            </p>
                                                            <p class="shrink-0 text-[11px] text-gray-500"
                                                               x-show="ccBenefitCap(row.cashback.highest)"
                                                               x-text="ccBenefitCap(row.cashback.highest)"></p>
                                                        </div>
                                                        <p class="mt-0.5 text-[11px] text-gray-600"
                                                           x-show="row.cashback.highest.note"
                                                           x-text="row.cashback.highest.note"></p>
                                                    </div>
                                                </template>

                                                {{-- Bậc chi tiết: hiện khi KHÔNG có tỷ lệ cao nhất, hoặc khi kết quả là
                                                     SẢN PHẨM dùng chung nhiều hạng thẻ — để bảo toàn khác biệt tỷ lệ theo hạng. --}}
                                                <template x-if="! row.cashback.highest || row.product_name">
                                                    <div class="space-y-2">
                                                        <template x-for="(tier, ti) in row.cashback.tiers" :key="'tier-' + row.id + '-' + ti">
                                                            <div class="flex items-start justify-between gap-3">
                                                                <div class="min-w-0">
                                                                    <p class="text-xs text-gray-700" x-text="tier.name"></p>
                                                                    <p class="text-[11px] text-gray-400" x-show="tier.has_rule"
                                                                       x-text="ccTierRange(tier.min_total_spend, tier.max_total_spend)"></p>
                                                                    <p class="text-[11px] text-gray-400" x-show="! tier.has_rule">Không áp dụng cho danh mục này</p>
                                                                    <p class="text-[11px] text-gray-500 mt-0.5" x-show="tier.note" x-text="tier.note"></p>
                                                                    <p class="text-[11px] text-rose-600 mt-0.5" x-show="tier.exception" x-text="tier.exception"></p>
                                                                </div>
                                                                <div class="text-right shrink-0">
                                                                    <p class="text-sm font-semibold text-emerald-700" x-show="tier.has_rule"
                                                                       x-text="ccBenefitRate(tier)"></p>
                                                                    <p class="text-[11px] text-gray-400"
                                                                       x-show="tier.has_rule && ccBenefitCap(tier)"
                                                                       x-text="ccBenefitCap(tier)"></p>
                                                                </div>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        <template x-if="! row.cashback || row.cashback.status === 'missing'">
                                            <p class="text-xs text-amber-700">Chưa có dữ liệu tỷ lệ hoàn tiền</p>
                                        </template>
                                    </div>

                                    <p class="text-xs text-gray-600" x-show="row.condition_note" x-text="row.condition_note"></p>
                                    <p class="text-xs text-rose-700" x-show="row.exclusion_reason" x-text="'Loại trừ: ' + row.exclusion_reason"></p>

                                    {{-- Chi tiết quy tắc nhóm MCC của dữ liệu import. --}}
                                    <p class="text-[11px] text-gray-500"
                                       x-show="row.match_type === 'group' && row.mcc_group"
                                       x-text="'Nhóm: ' + row.mcc_group"></p>
                                    <p class="text-[11px] text-gray-500"
                                       x-show="(row.mcc_codes || []).length"
                                       x-text="'Mã trong nhóm: ' + (row.mcc_codes || []).join(', ')"></p>

                                    {{-- Hồ sơ MCC admin nhập + phạm vi loại trừ --}}
                                    <p class="text-[11px] text-gray-600"
                                       x-show="row.profile_name"
                                       x-text="'Hồ sơ MCC: ' + row.profile_name"></p>
                                    <p class="text-[11px] text-gray-500"
                                       x-show="row.group_reference_name"
                                       x-text="'Nhóm trong hồ sơ: ' + row.group_reference_name"></p>
                                    <p class="text-[11px] text-rose-600"
                                       x-show="row.exclusion_scope === 'policy'"
                                       x-text="'Loại trừ theo hồ sơ MCC của chính sách'"></p>
                                    <p class="text-[11px] text-gray-500"
                                       x-show="row.note && (! row.cashback || row.cashback.status !== 'excluded')"
                                       x-text="row.note"></p>

                                    <p class="text-[11px] text-gray-400"
                                       x-show="row.source_pdf"
                                       x-text="'Nguồn: ' + row.source_pdf + (row.source_page ? ' · trang ' + row.source_page : '')"></p>
                                </li>
                            </template>
                        </ul>
                    </section>
                </div>
            </template>
        </section>

    </div>

    @once
        @include('credit-card.partials.money-js')

        <script>
            /** Khoảng chi tiêu của một bậc: "dưới X" / "X – Y" / "từ X trở lên". */
            function ccTierRange(min, max) {
                const from = Number(min ?? 0);

                if (max === null || max === undefined) {
                    return 'từ ' + ccMoneyVnd(from) + ' trở lên';
                }

                if (from === 0) {
                    return 'dưới ' + ccMoneyVnd(max);
                }

                return ccMoneyVnd(from) + ' – ' + ccMoneyVnd(max);
            }

            /** Hạn mức của một quy tắc hoàn tiền (mỗi giao dịch / mỗi danh mục). */
            function ccRuleCaps(rule) {
                const caps = [];

                if (rule.max_cashback_per_transaction) {
                    caps.push('tối đa ' + ccMoneyVnd(rule.max_cashback_per_transaction) + '/giao dịch');
                }

                if (rule.max_cashback_per_category_per_period) {
                    caps.push('tối đa ' + ccMoneyVnd(rule.max_cashback_per_category_per_period) + '/kỳ');
                }

                return caps.join(' · ');
            }

            /**
             * Tỷ lệ hoàn tiền hiển thị của một bậc: ưu tiên nhãn nguyên văn của
             * tài liệu ("tới 20%"), rồi tới tỷ lệ số (15% / 0,5%). Không suy diễn.
             */
            function ccBenefitRate(tier) {
                if (! tier) return '—';

                if (tier.rate_label) return tier.rate_label;

                if (tier.cashback_percent !== null && tier.cashback_percent !== undefined && tier.cashback_percent !== '') {
                    return ccNumber(tier.cashback_percent) + '%';
                }

                return '—';
            }

            /**
             * Trần hoàn tiền của một bậc: dữ liệu trích tài liệu (`cap_amount` +
             * `cap_unit` + `cap_period`) nếu có, ngược lại dùng hạn mức blueprint.
             */
            function ccBenefitCap(tier) {
                if (tier?.cap_amount) {
                    const isPoints = String(tier.cap_unit || '').toLowerCase() === 'điểm';
                    const amount = isPoints ? ccMoney(tier.cap_amount) + ' điểm' : ccMoneyVnd(tier.cap_amount);
                    const period = tier.cap_period ? '/' + tier.cap_period : '';

                    return 'tối đa ' + amount + period;
                }

                return ccRuleCaps(tier);
            }

            /** Lớp màu cho khối tóm tắt hoàn tiền theo trạng thái dữ liệu blueprint. */
            function ccCashbackBoxClass(cashback) {
                const status = cashback?.status;

                if (status === 'available') {
                    return 'border-emerald-100 bg-emerald-50/40';
                }

                if (status === 'excluded') {
                    return 'border-rose-100 bg-rose-50/40';
                }

                return 'border-amber-100 bg-amber-50/40';
            }

            /** Tổng số tài liệu tham khảo của mọi hồ sơ MCC trong kết quả. */
            function ccTotalDocuments(profiles) {
                return (profiles || []).reduce(
                    (total, profile) => total + ((profile.documents || []).length || 0),
                    0
                );
            }

            /** Giá trị phí thường niên hợp lệ (khác null/rỗng). NULL = chưa có dữ liệu. */
            function ccProfileFeeValue(value) {
                return value !== null && value !== undefined && value !== '';
            }

            /** Hồ sơ có ít nhất một thông tin phí thường niên để hiển thị. */
            function ccProfileHasFees(profile) {
                if (!profile) {
                    return false;
                }

                return ccProfileFeeValue(profile.annual_fee_primary)
                    || ccProfileFeeValue(profile.annual_fee_supplementary)
                    || ccProfileFeeValue(profile.annual_spend_for_annual_fee_waiver);
            }

            /** Nhãn trạng thái xác minh của mapping/MCC. */
            function ccVerificationLabel(status) {
                const labels = {
                    verified: 'Đã xác minh',
                    unverified: 'Chưa xác minh',
                    needs_review: 'Cần kiểm tra',
                };

                return labels[status] ?? 'Chưa xác minh';
            }

            /** Lớp màu cho nhãn quan hệ MCC ↔ chính sách. */
            function ccRelationClass(relation) {
                const classes = {
                    direct: 'bg-emerald-50 text-emerald-700',
                    group: 'bg-sky-50 text-sky-700',
                    broad: 'bg-indigo-50 text-indigo-700',
                    broad_except: 'bg-indigo-50 text-indigo-700',
                    excluded: 'bg-rose-50 text-rose-700',
                    unverified: 'bg-amber-50 text-amber-700',
                };

                return classes[relation] ?? 'bg-gray-100 text-gray-600';
            }

            /**
             * State trang "Tra cứu" — CHỈ giữ lựa chọn và kết quả; mọi truy vấn
             * nằm ở server (`CardPolicyLookupService`).
             */
            function cardLookup(config) {
                return {
                    tab: 'policy',
                    policyEndpoint: config.policyEndpoint,
                    mccEndpoint: config.mccEndpoint,

                    policyId: '',
                    policyLoading: false,
                    policyError: '',
                    policyFetchError: '',
                    policyResult: null,

                    mccCode: '',
                    mccLoading: false,
                    mccError: '',
                    mccFetchError: '',
                    mccResult: null,

                    async runPolicy() {
                        this.policyError = '';
                        this.policyFetchError = '';

                        if (this.policyId === '' || this.policyId === null) {
                            this.policyError = 'Vui lòng chọn thẻ cần tra cứu.';

                            return;
                        }

                        this.policyLoading = true;

                        try {
                            const url = new URL(this.policyEndpoint, window.location.origin);
                            url.searchParams.set('template_id', this.policyId);

                            const payload = await this.request(url, 'policyFetchError');

                            if (payload === null) return;

                            this.policyResult = payload.data;
                        } finally {
                            this.policyLoading = false;
                        }
                    },

                    async runMcc() {
                        this.mccError = '';
                        this.mccFetchError = '';

                        if (! /^\d{4}$/.test(this.mccCode)) {
                            this.mccError = 'Mã MCC phải gồm đúng 4 chữ số.';

                            return;
                        }

                        this.mccLoading = true;

                        try {
                            const url = new URL(this.mccEndpoint, window.location.origin);
                            url.searchParams.set('mcc', this.mccCode);

                            const payload = await this.request(url, 'mccFetchError');

                            if (payload === null) return;

                            this.mccResult = payload.data;
                        } finally {
                            this.mccLoading = false;
                        }
                    },

                    /**
                     * Gọi JSON; trả payload khi OK, ngược lại ghi lỗi vào ô chỉ
                     * định rồi trả null (không ném để state luôn nhất quán).
                     */
                    async request(url, errorKey) {
                        try {
                            const response = await fetch(url.toString(), {
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });

                            const payload = await response.json().catch(() => ({}));

                            if (! response.ok) {
                                this[errorKey] = this.firstError(payload) || 'Không tra cứu được. Vui lòng thử lại.';

                                return null;
                            }

                            return payload;
                        } catch (e) {
                            this[errorKey] = 'Không kết nối được máy chủ. Vui lòng thử lại.';

                            return null;
                        }
                    },

                    firstError(payload) {
                        if (payload?.errors) {
                            const first = Object.values(payload.errors)[0];

                            if (Array.isArray(first)) return first[0];
                        }

                        return payload?.message ?? '';
                    },
                };
            }
        </script>
    @endonce
</x-credit-card.layout>
