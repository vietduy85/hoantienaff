{{--
    /thetindung/chinh-sach — cấu hình chính sách cashback (Phase 1C).

    Blade KHÔNG nạp sẵn policy: mọi dữ liệu cấu hình lấy từ JSON API cùng origin.
    Lý do: nếu render sẵn ở server và lại gọi API khi bấm nút, sẽ có hai đường đọc
    cho cùng một dữ liệu và chúng sẽ trôi lệch nhau. Một đường đọc duy nhất.

    KHÔNG có ô nhập cashback kết quả ở bất kỳ đâu trên trang: tỷ lệ, cap và ngưỡng
    là cấu hình; tiền thật do hệ thống tính và hiển thị ở màn hình giao dịch.
--}}
<x-credit-card.layout
    title="Chính sách cashback"
    subtitle="Bậc chi tiêu, tỷ lệ hoàn tiền và giới hạn theo từng danh mục"
    active="policies">

    <div x-data="policyConfig(@js($userCreditCards->map(fn ($c) => [
        'id' => $c->id,
        'name' => $c->name,
        'bank' => $c->bank?->short_name ?? $c->bank?->name ?? '—',
        'has_policy' => $c->currentPolicy !== null,
    ])->values()))"
         x-init="init()"
         class="space-y-4 sm:space-y-5">

        {{-- Bộ chọn thẻ --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5">
            <label for="cc-policy-card" class="block text-sm font-semibold text-gray-800 mb-1.5">
                Chọn thẻ tín dụng
            </label>

            @if ($userCreditCards->isEmpty())
                <p class="text-sm text-gray-500">
                    Bạn chưa có thẻ nào.
                    <a href="{{ route('credit-cards.manage') }}" class="text-emerald-600 hover:underline font-medium">Thêm thẻ</a>
                    trước khi cấu hình chính sách.
                </p>
            @else
                <select id="cc-policy-card"
                        x-model="cardId"
                        @change="loadVersions()"
                        class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <template x-for="card in cards" :key="card.id">
                        <option :value="card.id" x-text="`${card.name} — ${card.bank}`"></option>
                    </template>
                </select>
            @endif
        </div>

        <template x-if="loading">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 text-sm text-gray-500">
                Đang tải cấu hình…
            </div>
        </template>

        <template x-if="error">
            <div class="rounded-2xl bg-rose-50 border border-rose-200 p-4 text-sm text-rose-700"
                 role="alert" x-text="error"></div>
        </template>

        <template x-if="!loading && !error && !hasPolicy">
            {{-- Chưa có policy: chọn đường tạo version 1 --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
                <h3 class="font-semibold text-gray-800 text-sm">Chưa có chính sách cho thẻ này</h3>

                <div class="grid gap-2 sm:grid-cols-3">
                    <template x-for="option in createModes" :key="option.mode">
                        <button type="button"
                                @click="createMode = option.mode"
                                :class="createMode === option.mode
                                    ? 'border-emerald-500 bg-emerald-50 ring-1 ring-emerald-200'
                                    : 'border-gray-200 bg-white hover:bg-gray-50'"
                                class="rounded-xl border p-3 text-left transition-colors">
                            <span class="block text-sm font-semibold text-gray-800" x-text="option.label"></span>
                            <span class="block text-xs text-gray-500 mt-0.5" x-text="option.hint"></span>
                        </button>
                    </template>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="cc-policy-from">Ngày bắt đầu hiệu lực</label>
                        <input id="cc-policy-from" type="date" x-model="effectiveFrom"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-gray-500">Áp dụng cho giao dịch có ngày chưa trước ngày này.</p>
                    </div>

                    <template x-if="createMode === 'scratch'">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="cc-policy-name">Tên chính sách</label>
                            <input id="cc-policy-name" type="text" x-model="draft.name" maxlength="150"
                                   placeholder="Ví dụ: Chính sách cashback 2026"
                                   class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                    </template>

                    <template x-if="createMode !== 'scratch'">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="cc-policy-template">Mẫu chính sách</label>
                            <select id="cc-policy-template" x-model="draft.template_id"
                                    class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <option value="">— Chọn mẫu —</option>
                                <template x-for="tpl in templates" :key="tpl.id">
                                    <option :value="tpl.id" x-text="`${tpl.name}${tpl.is_system ? ' (hệ thống)' : ''}`"></option>
                                </template>
                            </select>
                        </div>
                    </template>
                </div>

                <button type="button" @click="createPolicy()"
                        :disabled="busy"
                        class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                    Tạo chính sách
                </button>
            </div>
        </template>

        <template x-if="!loading && !error && hasPolicy">
            <div class="space-y-4 sm:space-y-5">

                {{-- Danh sách phiên bản --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <h3 class="font-semibold text-gray-800 text-sm">Phiên bản chính sách</h3>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="openNewVersion()"
                                    class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                                Tạo phiên bản mới
                            </button>
                            <button type="button" @click="openSaveTemplate()"
                                    class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50">
                                Lưu thành mẫu
                            </button>
                        </div>
                    </div>

                    <ul class="divide-y divide-gray-100">
                        <template x-for="version in versions" :key="version.id">
                            <li class="py-2 flex flex-wrap items-center gap-2 justify-between">
                                <button type="button" @click="openVersion(version.id)"
                                        class="text-left text-sm hover:underline">
                                    <span class="font-medium text-gray-800" x-text="version.name"></span>
                                    <span class="text-gray-400"> · v</span><span x-text="version.version_no"></span>
                                    <span class="block text-xs text-gray-500"
                                          x-text="`Hiệu lực từ ${version.effective_from}`"></span>
                                </button>
                                <span class="flex items-center gap-2">
                                    <span x-show="version.id === currentVersionId"
                                          class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Đang áp dụng</span>
                                    <span x-show="version.is_locked"
                                          class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600">Đã khoá</span>
                                    <span x-show="version.status === 'superseded'"
                                          class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600">Lịch sử</span>
                                </span>
                            </li>
                        </template>
                    </ul>

                    <p class="mt-3 text-xs text-gray-500">
                        Sửa cấu hình không sửa phiên bản cũ — hệ thống tạo phiên bản mới để kỳ đã
                        tính tiền không bị thay đổi.
                    </p>
                </div>

                {{-- Form tạo phiên bản mới --}}
                <template x-if="newVersion.open">
                    <div class="bg-white rounded-2xl shadow-sm border border-emerald-200 p-4 sm:p-5 space-y-3">
                        <h3 class="font-semibold text-gray-800 text-sm">Tạo phiên bản mới</h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="cc-nv-from">Ngày bắt đầu hiệu lực</label>
                            <input id="cc-nv-from" type="date" x-model="newVersion.effective_from"
                                   class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="cc-nv-name">Tên (để trống để giữ tên cũ)</label>
                            <input id="cc-nv-name" type="text" x-model="newVersion.name" maxlength="150"
                                   class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div class="flex gap-2">
                            <button type="button" @click="createVersion()"
                                    :disabled="busy"
                                    class="rounded-xl bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">Tạo</button>
                            <button type="button" @click="newVersion.open = false"
                                    class="rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50">Hủy</button>
                        </div>
                    </div>
                </template>

                {{-- Form lưu mẫu --}}
                <template x-if="saveTemplate.open">
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-3">
                        <h3 class="font-semibold text-gray-800 text-sm">Lưu cấu hình hiện tại thành mẫu của bạn</h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="cc-tpl-name">Tên mẫu</label>
                            <input id="cc-tpl-name" type="text" x-model="saveTemplate.name" maxlength="150"
                                   class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div class="flex gap-2">
                            <button type="button" @click="saveAsTemplate()"
                                    :disabled="busy"
                                    class="rounded-xl bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">Lưu mẫu</button>
                            <button type="button" @click="saveTemplate.open = false"
                                    class="rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50">Hủy</button>
                        </div>
                    </div>
                </template>

                {{-- Chi tiết phiên bản: bậc + rule --}}
                <template x-if="detail">
                    <div class="space-y-4">

                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5">
                            <h3 class="font-semibold text-gray-800 text-sm" x-text="detail.name"></h3>
                            <dl class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                <div class="flex gap-2">
                                    <dt class="text-gray-500">Ngưỡng tối thiểu:</dt>
                                    <dd class="font-medium text-gray-800" x-text="money(detail.min_total_spend)"></dd>
                                </div>
                                <div class="flex gap-2">
                                    <dt class="text-gray-500">Tổng hoàn tối đa / kỳ:</dt>
                                    <dd class="font-medium text-gray-800"
                                        x-text="detail.max_cashback_total_per_period === null ? 'Không giới hạn' : money(detail.max_cashback_total_per_period)"></dd>
                                </div>
                                <div class="flex gap-2">
                                    <dt class="text-gray-500">Cách làm tròn:</dt>
                                    <dd class="font-medium text-gray-800" x-text="detail.rounding_mode"></dd>
                                </div>
                                <div class="flex gap-2">
                                    <dt class="text-gray-500">Hiệu lực:</dt>
                                    <dd class="font-medium text-gray-800"
                                        x-text="`${detail.effective_from}${detail.effective_to ? ` → ${detail.effective_to}` : ''}`"></dd>
                                </div>
                            </dl>
                        </div>

                        <template x-for="tier in detail.tiers" :key="tier.id">
                            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h4 class="font-semibold text-gray-800 text-sm" x-text="tier.name"></h4>
                                        <p class="text-xs text-gray-500 mt-0.5">
                                            Chi tiêu từ
                                            <span class="font-medium" x-text="money(tier.min_total_spend)"></span>
                                            <span x-show="tier.max_total_spend !== null">
                                                đến <span class="font-medium" x-text="money(tier.max_total_spend)"></span>
                                            </span>
                                            <span x-show="tier.max_total_spend === null">trở lên</span>
                                        </p>
                                    </div>
                                    <div x-show="isEditable" class="flex gap-2">
                                        <button type="button" @click="openRuleForm(tier)"
                                                class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 border border-emerald-200 hover:bg-emerald-50">
                                            + Quy tắc
                                        </button>
                                        <button type="button" @click="cloneTier(tier)"
                                                class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50">
                                            Nhân bản
                                        </button>
                                        <button type="button" @click="removeTier(tier)"
                                                class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-rose-600 border border-rose-200 hover:bg-rose-50">
                                            Xoá
                                        </button>
                                    </div>
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-xs uppercase tracking-wide text-gray-400">
                                                <th class="py-1.5 pr-3 font-semibold">Danh mục</th>
                                                <th class="py-1.5 pr-3 font-semibold">Hoàn (%)</th>
                                                <th class="py-1.5 pr-3 font-semibold">Cap / giao dịch</th>
                                                <th class="py-1.5 pr-3 font-semibold">Cap / danh mục / kỳ</th>
                                                <th class="py-1.5 pr-3 font-semibold">Ngưỡng chi tiêu</th>
                                                <th class="py-1.5 font-semibold"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <template x-for="rule in tier.rules" :key="rule.id">
                                                <tr>
                                                    <td class="py-1.5 pr-3 text-gray-800" x-text="rule.category_name ?? `#${rule.category_id}`"></td>
                                                    <td class="py-1.5 pr-3 text-gray-800" x-text="rule.cashback_percent"></td>
                                                    <td class="py-1.5 pr-3 text-gray-800"
                                                        x-text="rule.max_cashback_per_transaction === null ? '—' : money(rule.max_cashback_per_transaction)"></td>
                                                    <td class="py-1.5 pr-3 text-gray-800"
                                                        x-text="rule.max_cashback_per_category_per_period === null ? '—' : money(rule.max_cashback_per_category_per_period)"></td>
                                                    <td class="py-1.5 pr-3 text-gray-800"
                                                        x-text="`${money(rule.spend_from)}${rule.spend_to === null ? '+' : ` – ${money(rule.spend_to)}`}`"></td>
                                                    <td class="py-1.5 text-right whitespace-nowrap">
                                                        <span x-show="isEditable" class="inline-flex gap-1.5">
                                                            <button type="button" @click="cloneRule(rule)"
                                                                    class="rounded-lg bg-white px-2 py-0.5 text-xs font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50">
                                                                Nhân bản
                                                            </button>
                                                            <button type="button" @click="removeRule(rule)"
                                                                    class="rounded-lg bg-white px-2 py-0.5 text-xs font-semibold text-rose-600 border border-rose-200 hover:bg-rose-50">
                                                                Xoá
                                                            </button>
                                                        </span>
                                                    </td>
                                                </tr>
                                            </template>
                                            <tr x-show="tier.rules.length === 0">
                                                <td colspan="6" class="py-3 text-center text-xs text-gray-400">
                                                    Bậc này chưa có quy tắc nào.
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>

                        <template x-if="isEditable">
                            <div class="bg-white rounded-2xl shadow-sm border border-dashed border-gray-300 p-4 sm:p-5 space-y-3">
                                <h3 class="font-semibold text-gray-800 text-sm">Thêm vào phiên bản này</h3>
                                <p class="text-xs text-gray-500">
                                    Bậc dùng ngưỡng TỔNG chi tiêu cả kỳ (retrospective). Không có chế độ
                                    tính dồn từng giao dịch.
                                </p>
                                <button type="button" @click="openTierForm()"
                                        class="rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50">
                                    + Thêm bậc
                                </button>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Form thêm bậc --}}
                <template x-if="tierForm.open">
                    <div class="bg-white rounded-2xl shadow-sm border border-emerald-200 p-4 sm:p-5 space-y-3">
                        <h3 class="font-semibold text-gray-800 text-sm">Thêm bậc chi tiêu</h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="cc-tier-name">Tên bậc</label>
                            <input id="cc-tier-name" type="text" x-model="tierForm.name" maxlength="150"
                                   class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="cc-tier-min">Chi tiêu tối thiểu</label>
                                <input id="cc-tier-min" type="number" min="0" step="0.01" x-model="tierForm.min_total_spend"
                                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="cc-tier-max">Chi tiêu tối đa (để trống = không giới hạn)</label>
                                <input id="cc-tier-max" type="number" min="0" step="0.01" x-model="tierForm.max_total_spend"
                                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" @click="saveTier()"
                                    :disabled="busy"
                                    class="rounded-xl bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">Lưu bậc</button>
                            <button type="button" @click="tierForm.open = false"
                                    class="rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50">Hủy</button>
                        </div>
                    </div>
                </template>

                {{-- Form thêm rule --}}
                <template x-if="ruleForm.open">
                    <div class="bg-white rounded-2xl shadow-sm border border-emerald-200 p-4 sm:p-5 space-y-3">
                        <h3 class="font-semibold text-gray-800 text-sm">Thêm quy tắc cashback</h3>

                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="cc-rule-tier">Bậc</label>
                            <select id="cc-rule-tier" x-model="ruleForm.tier_id"
                                    class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <template x-for="tier in (detail?.tiers ?? [])" :key="tier.id">
                                    <option :value="tier.id" x-text="tier.name"></option>
                                </template>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="cc-rule-category">Danh mục</label>
                            <select id="cc-rule-category" x-model="ruleForm.category_id"
                                    class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <option value="">— Chọn danh mục —</option>
                                <template x-for="cat in categories" :key="cat.id">
                                    <option :value="cat.id" x-text="`${cat.name}${cat.scope === 'system' ? '' : ' (của bạn)'}`"></option>
                                </template>
                            </select>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="cc-rule-percent">Tỷ lệ hoàn tiền (%)</label>
                                <input id="cc-rule-percent" type="number" min="0" max="100" step="0.001" x-model="ruleForm.cashback_percent"
                                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="cc-rule-cap-tx">Cap mỗi giao dịch (để trống = không giới hạn)</label>
                                <input id="cc-rule-cap-tx" type="number" min="0" step="0.01" x-model="ruleForm.max_cashback_per_transaction"
                                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="cc-rule-cap-cat">Cap mỗi danh mục trong kỳ</label>
                                <input id="cc-rule-cap-cat" type="number" min="0" step="0.01" x-model="ruleForm.max_cashback_per_category_per_period"
                                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="cc-rule-min-tx">Giao dịch tối thiểu</label>
                                <input id="cc-rule-min-tx" type="number" min="0" step="0.01" x-model="ruleForm.min_transaction_amount"
                                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="cc-rule-spend-from">Ngưỡng chi tiêu danh mục từ</label>
                                <input id="cc-rule-spend-from" type="number" min="0" step="0.01" x-model="ruleForm.spend_from"
                                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="cc-rule-spend-to">Ngưỡng chi tiêu danh mục đến</label>
                                <input id="cc-rule-spend-to" type="number" min="0" step="0.01" x-model="ruleForm.spend_to"
                                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                        </div>

                        <p class="text-xs text-gray-500">
                            Đây là <strong>cấu hình</strong>. Số tiền hoàn thực tế do hệ thống tính khi
                            giao dịch vào sổ — không nhập tay ở đây.
                        </p>

                        <div class="flex gap-2">
                            <button type="button" @click="saveRule()"
                                    :disabled="busy"
                                    class="rounded-xl bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">Lưu quy tắc</button>
                            <button type="button" @click="ruleForm.open = false"
                                    class="rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50">Hủy</button>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>

    @push('scripts')
        <script>
            window.policyConfig = function (cards) {
                return {
                    cards: cards,
                    cardId: cards.length ? cards[0].id : null,

                    versions: [],
                    currentVersionId: null,
                    detail: null,
                    templates: [],
                    categories: [],

                    loading: false,
                    busy: false,
                    error: '',

                    createMode: 'scratch',
                    effectiveFrom: new Date().toISOString().slice(0, 10),
                    draft: { name: '', template_id: '' },

                    newVersion: { open: false, effective_from: '', name: '' },
                    saveTemplate: { open: false, name: '' },
                    tierForm: { open: false, name: '', min_total_spend: 0, max_total_spend: '' },
                    ruleForm: {
                        open: false, tier_id: '', category_id: '', cashback_percent: 0,
                        max_cashback_per_transaction: '', max_cashback_per_category_per_period: '',
                        min_transaction_amount: '', spend_from: 0, spend_to: '',
                    },

                    createModes: [
                        { mode: 'scratch', label: 'Tự tạo', hint: 'Bắt đầu từ con số 0' },
                        { mode: 'clone_system', label: 'Từ mẫu hệ thống', hint: 'Dùng mẫu có sẵn' },
                        { mode: 'clone_user', label: 'Từ mẫu của tôi', hint: 'Dùng mẫu đã lưu' },
                    ],

                    get hasPolicy() {
                        return this.versions.length > 0;
                    },

                    /**
                     * Phiên bản đang mở chỉ sửa được khi chưa khoá và chưa bị thay thế.
                     * Điều này chỉ để GIẢM THAO TÁC cho người dùng; quyết định thật vẫn
                     * nằm ở Policy + service (nếu chỉ tin UI thì gọi API trực tiếp vẫn
                     * phải bị chặn).
                     */
                    get isEditable() {
                        if (!this.detail) return false;
                        return !this.detail.is_locked && this.detail.status !== 'superseded';
                    },

                    init() {
                        if (!this.cardId) return;
                        this.loadVersions();
                        this.loadTemplates();
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

                    cardBase() {
                        return `/thetindung/api/the/${this.cardId}/chinh-sach`;
                    },

                    async loadVersions() {
                        this.loading = true;
                        this.error = '';
                        this.detail = null;

                        const payload = await this.request(this.cardBase());
                        this.loading = false;
                        if (!payload) return;

                        this.versions = payload.data ?? [];
                        this.currentVersionId = payload.meta?.current_version_id ?? null;

                        if (this.currentVersionId) {
                            this.openVersion(this.currentVersionId);
                        }

                        await this.loadCategories();
                    },

                    async loadTemplates() {
                        const payload = await this.request('/thetindung/api/mau-chinh-sach');
                        if (payload) this.templates = payload.data ?? [];
                    },

                    async loadCategories() {
                        const tier = this.detail?.tiers?.[0];
                        if (!tier) {
                            this.categories = [];
                            return;
                        }

                        const payload = await this.request(`/thetindung/api/bac/${tier.id}/quy-tac`);
                        if (payload) this.categories = payload.meta?.categories ?? [];
                    },

                    async openVersion(versionId) {
                        const payload = await this.request(`${this.cardBase()}/${versionId}`);
                        if (payload) this.detail = payload.data ?? null;
                    },

                    async createPolicy() {
                        const body = {
                            mode: this.createMode,
                            effective_from: this.effectiveFrom,
                        };

                        if (this.createMode === 'scratch') {
                            body.name = this.draft.name;
                        } else {
                            body.template_id = this.draft.template_id;
                        }

                        const payload = await this.request(this.cardBase(), {
                            method: 'POST',
                            body: JSON.stringify(body),
                        });

                        if (payload) await this.loadVersions();
                    },

                    openNewVersion() {
                        this.newVersion = {
                            open: true,
                            effective_from: new Date().toISOString().slice(0, 10),
                            name: '',
                        };
                    },

                    async createVersion() {
                        const body = { effective_from: this.newVersion.effective_from };
                        if (this.newVersion.name !== '') body.name = this.newVersion.name;

                        const payload = await this.request(`${this.cardBase()}/phien-ban`, {
                            method: 'POST',
                            body: JSON.stringify(body),
                        });

                        if (payload) {
                            this.newVersion.open = false;
                            await this.loadVersions();
                        }
                    },

                    openSaveTemplate() {
                        this.saveTemplate = { open: true, name: '' };
                    },

                    async saveAsTemplate() {
                        const payload = await this.request(`${this.cardBase()}/mau`, {
                            method: 'POST',
                            body: JSON.stringify({ name: this.saveTemplate.name }),
                        });

                        if (payload) {
                            this.saveTemplate.open = false;
                            await this.loadTemplates();
                        }
                    },

                    openTierForm() {
                        this.tierForm = { open: true, name: '', min_total_spend: 0, max_total_spend: '' };
                    },

                    async saveTier() {
                        const body = {
                            name: this.tierForm.name,
                            min_total_spend: this.tierForm.min_total_spend,
                            max_total_spend: this.tierForm.max_total_spend === '' ? null : this.tierForm.max_total_spend,
                        };

                        const payload = await this.request(`/thetindung/api/chinh-sach/${this.detail.id}/bac`, {
                            method: 'POST',
                            body: JSON.stringify(body),
                        });

                        if (payload) {
                            this.tierForm.open = false;
                            this.openVersion(this.detail.id);
                        }
                    },

                    async removeTier(tier) {
                        if (!window.confirm(`Xoá bậc "${tier.name}"?`)) return;

                        const payload = await this.request(`/thetindung/api/bac/${tier.id}`, { method: 'DELETE' });

                        if (payload) this.openVersion(this.detail.id);
                    },

                    async cloneTier(tier) {
                        const other = this.versions.find((v) => v.id !== this.detail.id);
                        if (!other) {
                            window.alert('Cần có ít nhất một phiên bản khác để nhân bản sang.');
                            return;
                        }

                        if (!window.confirm(`Nhân bản bậc "${tier.name}" (kèm mọi quy tắc) sang phiên bản khác?`)) return;

                        const payload = await this.request(`/thetindung/api/bac/${tier.id}/nhan-ban`, {
                            method: 'POST',
                            body: JSON.stringify({ target_policy_id: other.id }),
                        });

                        if (payload) window.alert('Đã nhân bản bậc và toàn bộ quy tắc sang phiên bản đích.');
                    },

                    openRuleForm(tier) {
                        this.ruleForm = {
                            open: true,
                            tier_id: tier ? tier.id : (this.detail?.tiers?.[0]?.id ?? ''),
                            category_id: '', cashback_percent: 0,
                            max_cashback_per_transaction: '', max_cashback_per_category_per_period: '',
                            min_transaction_amount: '', spend_from: 0, spend_to: '',
                        };
                    },

                    async saveRule() {
                        const numericOrNull = (v) => (v === '' ? null : v);

                        const body = {
                            tier_id: this.ruleForm.tier_id,
                            category_id: this.ruleForm.category_id,
                            cashback_percent: this.ruleForm.cashback_percent,
                            max_cashback_per_transaction: numericOrNull(this.ruleForm.max_cashback_per_transaction),
                            max_cashback_per_category_per_period: numericOrNull(this.ruleForm.max_cashback_per_category_per_period),
                            min_transaction_amount: numericOrNull(this.ruleForm.min_transaction_amount),
                            spend_from: this.ruleForm.spend_from,
                            spend_to: numericOrNull(this.ruleForm.spend_to),
                        };

                        const payload = await this.request(`/thetindung/api/bac/${this.ruleForm.tier_id}/quy-tac`, {
                            method: 'POST',
                            body: JSON.stringify(body),
                        });

                        if (payload) {
                            this.ruleForm.open = false;
                            this.openVersion(this.detail.id);
                        }
                    },

                    async removeRule(rule) {
                        if (!window.confirm('Xoá quy tắc này?')) return;

                        const payload = await this.request(`/thetindung/api/quy-tac/${rule.id}`, { method: 'DELETE' });

                        if (payload) this.openVersion(this.detail.id);
                    },

                    async cloneRule(rule) {
                        const tier = this.detail?.tiers?.find((t) => t.rules.some((r) => r.id === rule.id));
                        if (!tier) return;

                        if (!window.confirm('Nhân bản quy tắc này sang bậc khác của phiên bản này?')) return;

                        const other = this.detail.tiers.find((t) => t.id !== tier.id);
                        if (!other) {
                            window.alert('Phiên bản này chỉ có một bậc. Hãy tạo thêm bậc trước.');
                            return;
                        }

                        const payload = await this.request(`/thetindung/api/quy-tac/${rule.id}/nhan-ban`, {
                            method: 'POST',
                            body: JSON.stringify({ target_tier_id: other.id }),
                        });

                        if (payload) this.openVersion(this.detail.id);
                    },

                    money(value) {
                        return new Intl.NumberFormat('vi-VN', {
                            style: 'currency',
                            currency: 'VND',
                            maximumFractionDigits: 0,
                        }).format(Number(value ?? 0));
                    },
                };
            };

            document.addEventListener('alpine:init', () => {
                window.Alpine.data('policyConfig', window.policyConfig);
            });
        </script>
    @endpush
</x-credit-card.layout>
