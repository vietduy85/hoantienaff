{{--
    Editor admin cho "Chính sách hoàn tiền hệ thống" — dùng chung cho trang
    Tạo mới (create) và Chỉnh sửa (edit).

    Dữ liệu:
      $initial        array  metadata + tiers (server-side, qua present())
      $endpoint       string URL JSON cần POST (tạo mới / "Lưu phiên bản mới")
      $updateEndpoint ?string  URL PATCH cập nhật in-place version ("Lưu lại") — chỉ có trên trang
                              Chỉnh sửa; khi có, editor hiện 2 nút Lưu lại / Lưu phiên bản mới
      $submitLabel    string nhãn nút lưu (chỉ dùng trang Tạo mới)
      $categories     list<{id, name}> danh mục hệ thống đang active
      $combos         list<{id, name, category_count}> combo hệ thống (đã ẩn vẫn nạp
                      để rule cũ không mất nhãn khi mở lại editor)
      $sourceVersionId ?int  phiên bản làm nguồn copy (luồng "Chỉnh sửa version N")
      $viewMode       bool   CHẾ ĐỘ XEM — trang "[Xem]" dùng chung editor này ở chế độ
                             read-only: mọi ô nhập bị khoá, không có bất kỳ nút thêm/xoá/lưu nào.

    CẤU TRÚC CANONICAL: `tiers[].rules` ở MỌI nơi — presenter xuất `rules`, editor giữ
    state `tier.rules` và payload gửi `rules`. KHÔNG có `tier.categories` (bug cũ rules
    ↔ categories đã từng xoá sạch rule khi lưu). Giới hạn hoàn tiền theo giá trị giao
    dịch nằm ở `tiers[].transaction_caps` (tài sản của BẬC, §23) — KHÔNG còn ở rule.

    Trần hoàn mỗi kỳ nằm ở TỪNG BẬC (`tiers[].max_cashback_per_period`) — không còn
    ô "Hoàn tiền tối đa / kỳ" ở cấp chính sách.

    KHÔNG nhập tiền hoàn "bên trái" ở đây: bậc/tỷ lệ/cap là CẤU HÌNH, tiền thật do
    hệ thống tính khi giao dịch vào sổ.
--}}
@php
    $endpoint = $endpoint ?? '';
    $updateEndpoint = $updateEndpoint ?? null;
    $submitLabel = $submitLabel ?? 'Lưu';
    $categories = $categories ?? [];
    $combos = $combos ?? [];
    $sourceVersionId = $sourceVersionId ?? null;
    $viewMode = $viewMode ?? false;
    $initial = $initial ?? [
        'name' => '',
        'description' => '',
        'effective_from' => now()->toDateString(),
        'status' => 'published',
        'tiers' => [],
    ];
@endphp

<div x-data="systemPolicyEditor(@js($initial), @js($endpoint), @js($categories), @js($sourceVersionId),
@js($updateEndpoint), @js($viewMode), @js($combos))"
     class="space-y-4 sm:space-y-5">

    <div x-show="error" class="rounded-2xl bg-rose-50 border border-rose-200 p-4 text-sm text-rose-700"
         role="alert" x-text="error"></div>

    {{-- Metadata chính sách --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-3">
        <h3 class="font-semibold text-gray-800 text-sm">Thông tin chính sách</h3>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700" for="cc-sp-name">Tên chính sách</label>
                <input id="cc-sp-name" type="text" x-model="meta.name" maxlength="150" required :disabled="viewMode"
                       placeholder="Ví dụ: MB JCB Ultimate"
                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700" for="cc-sp-desc">Mô tả</label>
                <textarea id="cc-sp-desc" x-model="meta.description" rows="2" maxlength="1000" :disabled="viewMode"
                          class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700" for="cc-sp-from">Ngày bắt đầu hiệu lực</label>
                <input id="cc-sp-from" type="date" x-model="meta.effective_from" :disabled="viewMode"
                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700" for="cc-sp-status">Trạng thái</label>
                <select id="cc-sp-status" x-model="meta.status" :disabled="viewMode"
                        class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                    <option value="draft">Nháp</option>
                    <option value="published">Đang sử dụng</option>
                    <option value="archived">Lưu trữ</option>
                </select>
            </div>
        </div>
    </div>

    {{-- Bậc chi tiêu & quy tắc --}}
    <div class="space-y-4 sm:space-y-5">
        <div class="flex items-center justify-between">
            <h3 class="font-semibold text-gray-800 text-sm">Bậc chi tiêu & quy tắc hoàn tiền</h3>
            <button type="button" @click="addTier()" x-show="!viewMode"
                    class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 border border-emerald-200 hover:bg-emerald-50">
                + Thêm bậc chi tiêu
            </button>
        </div>

        <p class="text-xs text-gray-500">
            Bậc dùng ngưỡng TỔNG chi tiêu cả kỳ (retrospective) — không có chế độ tính
            dồn từng giao dịch. Đây là <strong>cấu hình</strong>; số tiền hoàn thực tế do
            hệ thống tính khi giao dịch vào sổ.
        </p>

        <template x-for="(tier, ti) in tiers" :key="ti">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 class="font-semibold text-gray-800 text-sm">Bậc chi tiêu <span x-text="ti + 1"></span></h4>
                    <button type="button" @click="removeTier(ti)" x-show="!viewMode"
                            class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-rose-600 border border-rose-200 hover:bg-rose-50">
                        Xoá bậc
                    </button>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" :for="`cc-t${ti}-name`">Tên bậc</label>
                        <input :id="`cc-t${ti}-name`" type="text" x-model="tier.name" maxlength="150" :disabled="viewMode"
                               placeholder="Ví dụ: Chi tiêu cơ bản"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" :for="`cc-t${ti}-min`">Tổng chi tiêu tối thiểu</label>
                        <input :id="`cc-t${ti}-min`" type="number" min="0" step="0.01" x-model="tier.min_total_spend" :disabled="viewMode"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                        <p class="mt-1 text-xs text-gray-500">Mức chi tiêu tối thiểu để đạt bậc này.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" :for="`cc-t${ti}-max`">Tổng chi tiêu tối đa</label>
                        <input :id="`cc-t${ti}-max`" type="number" min="0" step="0.01" x-model="tier.max_total_spend" :disabled="viewMode"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                        <p class="mt-1 text-xs text-gray-500">Mức chi tiêu cao nhất thuộc bậc này. Để trống nếu không giới hạn.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" :for="`cc-t${ti}-cap`">Hoàn tiền tối đa của bậc / kỳ</label>
                        <input :id="`cc-t${ti}-cap`" type="number" min="0" step="0.01" x-model="tier.max_cashback_per_period" :disabled="viewMode"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                        <p class="mt-1 text-xs text-gray-500">Tổng số tiền hoàn tối đa của bậc này trong một kỳ. Để trống nếu không giới hạn.</p>
                    </div>
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" x-model="tier.use_transaction_caps" :disabled="viewMode"
                           @change="onTransactionCapsToggle(tier)"
                           class="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 disabled:bg-gray-50">
                    <span>
                        <span class="font-medium text-gray-700">Giới hạn hoàn tiền theo giá trị giao dịch</span>
                        <span class="block text-xs text-gray-500">Bật để giới hạn hoàn tiền MỖI GIAO DỊCH trong bậc này theo
                        giá trị giao dịch. Khi giao dịch khớp một khoảng, cap của khoảng đó
                        <strong>thay thế</strong> “Hoàn tối đa / giao dịch” của mọi quy tắc trong bậc.</span>
                    </span>
                </label>

                <template x-if="tier.use_transaction_caps">
                    <div class="rounded-xl border border-emerald-100 bg-emerald-50/40 p-3 space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-semibold text-emerald-700">Điều kiện theo giá trị giao dịch</p>
                            <button type="button" @click="addTransactionCap(tier)" x-show="!viewMode"
                                    class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 border border-emerald-200 hover:bg-emerald-50">
                                + Thêm khoảng
                            </button>
                        </div>

                        <template x-for="(cap, ci) in tier.transaction_caps" :key="ci">
                            <div class="grid gap-2 grid-cols-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600" :for="`cc-t${ti}-tc${ci}-min`">Từ</label>
                                    <input :id="`cc-t${ti}-tc${ci}-min`" type="number" min="0" step="0.01" x-model="cap.min_transaction_amount" :disabled="viewMode"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600" :for="`cc-t${ti}-tc${ci}-max`">Đến</label>
                                    <input :id="`cc-t${ti}-tc${ci}-max`" type="number" min="0" step="0.01" x-model="cap.max_transaction_amount" :disabled="viewMode"
                                           placeholder="Không giới hạn"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600" :for="`cc-t${ti}-tc${ci}-cap`">Hoàn tối đa / giao dịch</label>
                                    <input :id="`cc-t${ti}-tc${ci}-cap`" type="number" min="0" step="0.01" x-model="cap.max_cashback_per_transaction" :disabled="viewMode"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                </div>
                                <button type="button" @click="removeTransactionCap(tier, ci)" x-show="!viewMode"
                                        class="rounded-lg bg-white px-2.5 py-1.5 text-xs font-semibold text-rose-600 border border-rose-200 hover:bg-rose-50 sm:mb-0.5">
                                    Xoá
                                </button>
                            </div>
                        </template>

                        <p class="text-xs text-gray-500">
                            Áp dụng cho <strong>mọi quy tắc</strong> trong bậc. Giá trị giao dịch <strong>từ “Từ” đến “Đến”</strong>
                            (bao gồm cả hai đầu) sẽ nhận tối đa mức hoàn của khoảng đó. Để trống “Đến” nghĩa là không giới hạn trên.
                            Các khoảng trong cùng một bậc <strong>không được chồng lấn hoặc trùng nhau</strong>.
                        </p>
                    </div>
                </template>

                <div class="space-y-3">
                    <template x-for="(rule, ri) in tier.rules" :key="ri">
                        <div class="rounded-xl border border-gray-100 bg-gray-50/50 p-3 sm:p-4 space-y-3">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                    Quy tắc <span x-text="ri + 1"></span>
                                    <span x-show="rule.scope_type === 'other'"
                                          class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700 normal-case">
                                        📦 Các danh mục còn lại
                                    </span>
                                    <span x-show="rule.target_type === 'combo' && rule.scope_type === 'category'"
                                          class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 normal-case">
                                        🍱 <span x-text="comboLabel(rule.combo_id)"></span>
                                    </span>
                                </p>
                                <button type="button" @click="removeRule(tier, ri)" x-show="!viewMode"
                                        class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-rose-600 border border-rose-200 hover:bg-rose-50">
                                    Xoá
                                </button>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-scope`">Phạm vi danh mục</label>
                                    <select :id="`cc-r${ti}-${ri}-scope`" x-model="rule.scope_type" @change="onScopeChange(tier, rule)" :disabled="viewMode"
                                            class="mt-1 block w-full min-h-[44px] rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                        <option value="category">Danh mục cụ thể</option>
                                        <option value="other">📦 Các danh mục còn lại</option>
                                    </select>
                                </div>
                                <div x-show="rule.scope_type === 'category'" x-cloak>
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-target`">Loại mục tiêu</label>
                                    <select :id="`cc-r${ti}-${ri}-target`" x-model="rule.target_type" @change="onTargetTypeChange(rule)" :disabled="viewMode"
                                            class="mt-1 block w-full min-h-[44px] rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                        <option value="category">Danh mục</option>
                                        <option value="combo">🍱 Combo danh mục</option>
                                    </select>
                                </div>
                                <div x-show="rule.scope_type === 'category' && rule.target_type === 'category'">
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-cat`">Danh mục</label>
                                    <select :id="`cc-r${ti}-${ri}-cat`" x-model="rule.category_id" :disabled="viewMode"
                                            x-init="$nextTick(() => { $el.value = rule.category_id ?? ''; })"
                                            class="mt-1 block w-full min-h-[44px] rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                        <option value="">— Chọn danh mục —</option>
                                        <template x-for="cat in categories" :key="cat.id">
                                            <option :value="cat.id" x-text="cat.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div x-show="rule.scope_type === 'category' && rule.target_type === 'combo'" x-cloak>
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-combo`">Combo</label>
                                    <select :id="`cc-r${ti}-${ri}-combo`" x-model="rule.combo_id" :disabled="viewMode"
                                            x-init="$nextTick(() => { $el.value = rule.combo_id ?? ''; })"
                                            class="mt-1 block w-full min-h-[44px] rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                        <option value="">— Chọn combo —</option>
                                        <template x-for="combo in combos" :key="combo.id">
                                            <option :value="combo.id"
                                                    x-text="combo.name + ' (' + combo.category_count + ' danh mục)'"></option>
                                        </template>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">
                                        Một quy tắc trỏ tới combo sẽ tính hoàn trên <strong>tổng</strong> chi tiêu của
                                        mọi danh mục trong combo. Danh mục có quy tắc riêng sẽ thắng (ưu tiên
                                        danh mục &gt; combo &gt; mặc định).
                                    </p>
                                </div>
                                <div x-show="rule.scope_type === 'other'" class="sm:col-span-1">
                                    <label class="block text-sm font-medium text-gray-700">&nbsp;</label>
                                    <p class="mt-1 text-xs text-gray-500 leading-relaxed">
                                        Quy tắc mặc định áp cho mọi danh mục chưa có quy tắc cụ thể
                                        trong bậc này. Mỗi bậc chỉ có <strong>một</strong> quy tắc này.
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-pct`">Hoàn tiền (%)</label>
                                    <input :id="`cc-r${ti}-${ri}-pct`" type="number" min="0" max="100" step="0.001" x-model="rule.cashback_percent" :disabled="viewMode"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-cap-tx`">Hoàn tối đa / giao dịch</label>
                                    <input :id="`cc-r${ti}-${ri}-cap-tx`" type="number" min="0" step="0.01" x-model="rule.max_cashback_per_transaction" :disabled="viewMode"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                    <p class="mt-1 text-xs text-gray-500">Số tiền hoàn cao nhất cho một giao dịch.</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-cap-cat`">Hoàn tối đa / danh mục</label>
                                    <input :id="`cc-r${ti}-${ri}-cap-cat`" type="number" min="0" step="0.01" x-model="rule.max_cashback_per_category_per_period" :disabled="viewMode"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                    <p class="mt-1 text-xs text-gray-500">Tổng số tiền hoàn cao nhất của danh mục trong một kỳ sao kê.</p>
                                </div>
                            </div>

                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" x-model="rule.counts_toward_tier_cap" :disabled="viewMode"
                                       class="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 disabled:bg-gray-50">
                                <span>
                                    <span class="font-medium text-gray-700">Tính vào giới hạn hoàn tiền của bậc</span>
                                    <span class="block text-xs text-gray-500">Bật nếu hoàn tiền của quy tắc này tiêu tốn trần
                                    “Hoàn tiền tối đa của bậc / kỳ”.</span>
                                </span>
                            </label>
                        </div>
                    </template>

                    <div x-show="tier.rules.length === 0"
                         class="rounded-xl bg-white border border-dashed border-gray-300 p-4 text-center text-xs text-gray-400">
                        Bậc này chưa có quy tắc nào.
                    </div>
                </div>

                <button type="button" @click="addRule(tier)" x-show="!viewMode"
                        class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 border border-emerald-200 hover:bg-emerald-50">
                    + Thêm quy tắc cashback
                </button>
            </div>
        </template>

        <div x-show="tiers.length === 0"
             class="rounded-2xl bg-gray-50 border border-dashed border-gray-300 p-4 text-sm text-gray-500">
            Chưa có bậc chi tiêu nào. Bấm "+ Thêm bậc chi tiêu" để bắt đầu.
        </div>
    </div>

    {{-- Hành động (chỉ khi KHÔNG phải chế độ xem) --}}
    @if (! $viewMode)
    <template x-if="updateEndpoint">
        <div class="flex flex-col gap-2 sm:flex-row">
            <button type="button" @click="submit('current')" :disabled="busy"
                    class="rounded-xl bg-emerald-600 px-4 py-2.5 min-h-[44px] text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 w-full sm:w-auto">
                Lưu lại
            </button>
            <button type="button" @click="submit('new')" :disabled="busy"
                    class="rounded-xl bg-white px-4 py-2.5 min-h-[44px] text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50 w-full sm:w-auto">
                Lưu phiên bản mới
            </button>
            <a href="{{ route('admin.credit-card-policies.index') }}"
               class="rounded-xl bg-white px-4 py-2.5 min-h-[44px] text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50 text-center w-full sm:w-auto">
                Hủy
            </a>
        </div>
    </template>
    <template x-if="!updateEndpoint">
        <div class="flex flex-col gap-2 sm:flex-row">
            <button type="button" @click="submit('new')" :disabled="busy"
                    class="rounded-xl bg-emerald-600 px-4 py-2.5 min-h-[44px] text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 w-full sm:w-auto">
                {{ $submitLabel }}
            </button>
            <a href="{{ route('admin.credit-card-policies.index') }}"
               class="rounded-xl bg-white px-4 py-2.5 min-h-[44px] text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50 text-center w-full sm:w-auto">
                Hủy
            </a>
        </div>
    </template>
    @endif
</div>

@push('scripts')
<script>
    window.systemPolicyEditor = function (initial, endpoint, categories, sourceVersionId, updateEndpoint = null, viewMode = false, combos = []) {
        return {
            sourceVersionId: sourceVersionId ?? null,
            updateEndpoint: updateEndpoint ?? null,
            viewMode: viewMode ?? false,
            meta: {
                name: initial.name ?? '',
                description: initial.description ?? '',
                effective_from: initial.effective_from ?? '',
                status: initial.status ?? 'published',
            },
            // Cấu trúc canonical `tiers[].rules` được giữ NGUYÊN từ presenter vet qua editor.
            // Giữ cả `id` của tier/rule để "Lưu lại" (PATCH) cập nhật in-place đúng dòng.
            tiers: Array.isArray(initial.tiers)
                ? JSON.parse(JSON.stringify(initial.tiers)).map((tier) => {
                    const tierCaps = Array.isArray(tier.transaction_caps) ? tier.transaction_caps : [];

                    return {
                        id: tier.id ?? null,
                        name: tier.name ?? '',
                        sort_order: tier.sort_order ?? 0,
                        min_total_spend: tier.min_total_spend ?? 0,
                        max_total_spend: tier.max_total_spend ?? '',
                        max_cashback_per_period: tier.max_cashback_per_period ?? '',
                        use_transaction_caps: tierCaps.length > 0,
                        transaction_caps: tierCaps.map((cap) => ({
                            min_transaction_amount: cap.min_transaction_amount ?? 0,
                            max_transaction_amount: cap.max_transaction_amount ?? '',
                            max_cashback_per_transaction: cap.max_cashback_per_transaction ?? '',
                        })),
                        rules: (tier.rules ?? []).map((rule) => ({
                            id: rule.id ?? null,
                            scope_type: rule.scope_type ?? 'category',
                            // Target thứ hai: `category` (danh mục) | `combo`. Presenter
                            // xuất `target_type`; rule cũ chỉ có `category_id` vẫn mặc
                            // định `category` ⇒ payload giữ nguyên như trước.
                            target_type: rule.target_type ?? ((rule.combo_id ?? null) !== null ? 'combo' : 'category'),
                            counts_toward_tier_cap: rule.scope_type === 'other'
                                ? (rule.counts_toward_tier_cap ?? false)
                                : (rule.counts_toward_tier_cap ?? true),
                            category_id: rule.category_id ?? '',
                            combo_id: rule.combo_id ?? '',
                            name: rule.name ?? null,
                            cashback_percent: rule.cashback_percent ?? 0,
                            max_cashback_per_transaction: rule.max_cashback_per_transaction ?? '',
                            max_cashback_per_category_per_period: rule.max_cashback_per_category_per_period ?? '',
                            min_transaction_amount: rule.min_transaction_amount ?? '',
                        })),
                    };
                })
                : [],
            categories: categories,
            combos: combos,
            busy: false,
            error: '',

            addTier() {
                this.tiers.push({
                    id: null,
                    name: '',
                    sort_order: this.tiers.length + 1,
                    min_total_spend: 0,
                    max_total_spend: '',
                    max_cashback_per_period: '',
                    use_transaction_caps: false,
                    transaction_caps: [],
                    rules: [],
                });
            },

            removeTier(idx) {
                this.tiers.splice(idx, 1);
            },

            addRule(tier) {
                tier.rules.push({
                    id: null,
                    scope_type: 'category',
                    target_type: 'category',
                    counts_toward_tier_cap: true,
                    category_id: '',
                    combo_id: '',
                    name: null,
                    cashback_percent: 0,
                    max_cashback_per_transaction: '',
                    max_cashback_per_category_per_period: '',
                    min_transaction_amount: '',
                });
            },

            addTransactionCap(tier) {
                tier.transaction_caps.push({
                    min_transaction_amount: 0,
                    max_transaction_amount: '',
                    max_cashback_per_transaction: '',
                });
            },

            removeTransactionCap(tier, idx) {
                tier.transaction_caps.splice(idx, 1);
            },

            onTransactionCapsToggle(tier) {
                if (tier.use_transaction_caps && tier.transaction_caps.length === 0) {
                    tier.transaction_caps.push({
                        min_transaction_amount: 0,
                        max_transaction_amount: '',
                        max_cashback_per_transaction: '',
                    });
                } else if (!tier.use_transaction_caps) {
                    tier.transaction_caps = [];
                }
            },

            removeRule(tier, idx) {
                const rule = tier.rules[idx];

                if (rule && rule.scope_type === 'other') {
                    this.error = "Quy tắc '📦 Các danh mục còn lại' là quy tắc mặc định của mỗi bậc và không thể xóa.";
                    return;
                }

                tier.rules.splice(idx, 1);
            },

            onScopeChange(tier, rule) {
                if (rule.scope_type === 'other') {
                    if (tier.rules.some((other) => other !== rule && other.scope_type === 'other')) {
                        this.error = "Chỉ được có một quy tắc '📦 Các danh mục còn lại' trong mỗi bậc.";
                        rule.scope_type = 'category';
                        return;
                    }

                    rule.counts_toward_tier_cap = false;
                    // Fallback không mang target: service ép null cả hai, editor gửi
                    // null luôn để payload không mang id mồ côi.
                    rule.category_id = null;
                    rule.combo_id = null;
                    return;
                }

                // Quay lại rule cụ thể: chỉ khôi phục ô nhập của target đang chọn.
                if (rule.target_type === 'combo') {
                    if (rule.combo_id === null) {
                        rule.combo_id = '';
                    }
                } else if (rule.category_id === null) {
                    rule.category_id = '';
                }
            },

            // Đổi giữa "danh mục" và "combo": xoá target cũ để không bao giờ gửi
            // payload mang CẢ hai (server sẽ 422 vì loại trừ lẫn nhau).
            onTargetTypeChange(rule) {
                if (rule.target_type === 'combo') {
                    rule.category_id = null;
                    if (rule.combo_id === null) {
                        rule.combo_id = '';
                    }
                } else {
                    rule.combo_id = null;
                    if (rule.category_id === null) {
                        rule.category_id = '';
                    }
                }
            },

            // Combo đã ẩn vẫn có trong danh sách để không mất nhãn rule cũ.
            comboLabel(comboId) {
                if (comboId === null || comboId === '') {
                    return 'Combo';
                }

                const found = this.combos.find((combo) => String(combo.id) === String(comboId));

                return found ? found.name : 'Combo đã ẩn';
            },

            num(value) {
                if (value === '' || value === null || value === undefined) return null;
                return Number(value);
            },

            // Id target: ô chưa chọn ⇒ null (KHÔNG phải NaN từ Number('')), để server
            // báo đúng lỗi "Vui lòng chọn..." thay vì lỗi kiểu dữ liệu.
            targetId(value) {
                if (value === '' || value === null || value === undefined) return null;
                const parsed = Number(value);

                return Number.isFinite(parsed) ? parsed : null;
            },

            // Chỉ cấu hình thuộc VERSION — thân của "Lưu lại" (PATCH versions.update).
            versionConfig() {
                return {
                    effective_from: this.meta.effective_from,
                    tiers: this.tiers.map((tier, ti) => ({
                        id: tier.id ?? null,
                        name: tier.name === '' ? ('Bậc ' + (ti + 1)) : tier.name,
                        sort_order: ti + 1,
                        min_total_spend: this.num(tier.min_total_spend),
                        max_total_spend: this.num(tier.max_total_spend),
                        max_cashback_per_period: this.num(tier.max_cashback_per_period),
                        transaction_caps: tier.use_transaction_caps
                            ? (tier.transaction_caps ?? []).map((cap) => ({
                                min_transaction_amount: this.num(cap.min_transaction_amount),
                                max_transaction_amount: this.num(cap.max_transaction_amount),
                                max_cashback_per_transaction: this.num(cap.max_cashback_per_transaction),
                            }))
                            : [],
                        rules: (tier.rules ?? []).map((rule) => {
                            const scope = rule.scope_type ?? 'category';
                            const isFallback = scope === 'other';
                            // Target: fallback ⇒ cả hai null; còn lại chỉ MỘT trong
                            // category_id / combo_id được gửi (ưu tiên target_type).
                            const useCombo = ! isFallback && (rule.target_type ?? 'category') === 'combo';

                            return {
                                id: rule.id ?? null,
                                scope_type: scope,
                                counts_toward_tier_cap: rule.counts_toward_tier_cap ?? (isFallback ? false : true),
                                category_id: isFallback || useCombo ? null : this.targetId(rule.category_id),
                                combo_id: isFallback || ! useCombo ? null : this.targetId(rule.combo_id),
                                name: rule.name ?? null,
                                cashback_percent: this.num(rule.cashback_percent),
                                max_cashback_per_transaction: this.num(rule.max_cashback_per_transaction),
                                max_cashback_per_category_per_period: this.num(rule.max_cashback_per_category_per_period),
                                min_transaction_amount: this.num(rule.min_transaction_amount),
                            };
                        }),
                    })),
                };
            },

            // Payload đầy đủ cho "Lưu phiên bản mới" / tạo mới: metadata template +
            // cấu hình version. Nguồn copy để và giữ id NGHĨA LÀ SỬA; bấm nút này luôn
            // TẠO phiên bản kế tiếp, không được lẫn với "Lưu lại".
            payload() {
                const payload = {
                    ...this.versionConfig(),
                    name: this.meta.name,
                    description: this.meta.description === '' ? null : this.meta.description,
                    status: this.meta.status,
                };

                if (this.sourceVersionId !== null) {
                    payload.source_version_id = this.sourceVersionId;
                }

                return payload;
            },

            async submit(mode) {
                if (this.tiers.length === 0) {
                    this.error = 'Chính sách phải có ít nhất một bậc chi tiêu.';
                    return;
                }

                const isCurrent = mode === 'current';
                const url = isCurrent ? this.updateEndpoint : endpoint;

                if (!url) {
                    this.error = 'Không có đích lưu cho hành động này.';
                    return;
                }

                this.busy = true;
                this.error = '';

                try {
                    const response = await fetch(url, {
                        method: isCurrent ? 'PATCH' : 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        },
                        body: JSON.stringify(isCurrent ? this.versionConfig() : this.payload()),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        this.error = data.message
                            || (data.errors ? Object.values(data.errors)[0][0] : `Yêu cầu thất bại (${response.status}).`);
                        this.busy = false;
                        return;
                    }

                    // API có thể trả `redirect` (vd: clone → trang sửa chính sách MỚI);
                    // không có thì quay về danh sách như trước.
                    window.location.href = data?.redirect || @js(route('admin.credit-card-policies.index'));
                } catch (e) {
                    this.error = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                    this.busy = false;
                }
            },
        };
    };

    document.addEventListener('alpine:init', () => {
        window.Alpine.data('systemPolicyEditor', window.systemPolicyEditor);
    });
</script>
@endpush