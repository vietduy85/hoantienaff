{{--
    Policy Editor CANONICAL — dùng chung cho MỌI màn hình sửa cấu hình hoàn tiền:
      - Admin: Tạo mới / Chỉnh sửa / [Xem] chính sách hệ thống.
      - User:  form Thêm / Sửa thẻ (chính sách riêng của thẻ).

    Đây là partial DUY NHẤT. Không copy sang trang khác: mọi khác biệt về giao diện
    được xử lý bằng props bên dưới, state/payload thì dùng chung `policyEditorState()`.

    ---------------------------------------------------------------------------
    DATA
    ---------------------------------------------------------------------------
      $initial        array  metadata + tiers (server-side, qua presenter)
      $endpoint       string URL JSON cần POST (tạo mới / "Lưu phiên bản mới")
      $updateEndpoint ?string  URL PATCH cập nhật in-place version ("Lưu lại") — chỉ có trên trang
                              Chỉnh sửa; khi có, editor hiện 2 nút Lưu lại / Lưu phiên bản mới
      $submitLabel    string nhãn nút lưu (chỉ dùng trang Tạo mới)
      $categories     list<{id, name}> danh mục editor được phép chọn
      $combos         list<{id, name, category_count}> combo editor được phép chọn.
                      Nạp target đã ẩn hay không là việc của màn hình chủ: Admin
                      nạp cả combo ẩn, còn form Thẻ chỉ nạp combo active — đều OK,
                      vì `categoryLabel()`/`comboLabel()` tự hiện "… đã ẩn" cho id
                      không có trong danh sách, và `id` đó VẪN được giữ trong state
                      (LƯU Ý: re-save rule đang trỏ target ẩn vẫn bị
                      `ValidatesRuleTargets` từ chối — xem note cuối partial).
      $sourceVersionId ?int  phiên bản làm nguồn copy (luồng "Chỉnh sửa version N")
      $viewMode       bool   CHẾ ĐỘ XEM: mọi ô nhập bị khoá, không có nút thêm/xoá/lưu nào.

    ---------------------------------------------------------------------------
    CHẾ ĐỘ HOSTED (form Thẻ)
    ---------------------------------------------------------------------------
      $hosted          bool   true ⇒ editor KHÔNG tự sinh `x-data` và KHÔNG có nút lưu:
                              nút "Lưu thẻ" của form cha gửi đi. Editor chỉ lo phần state.
      $scopePrefix     ?string tên state trên scope CHA (mặc định `policyEditor.` khi hosted).
                              Mọi binding bỏ tiền tố này; biến `x-for` (`tier`, `rule`, `cap`)
                              vẫn là biến cục bộ nên KHÔNG bỏ tiền tố.
      $showTemplateMeta bool   false ⇒ ẩn "Mô tả"/"Trạng thái" (thuộc TEMPLATE, không có
                              trong policy riêng của thẻ).
      $showPolicyMeta   bool   false ⇒ ẩn "Tên chính sách" + "Ngày bắt đầu hiệu lực".
                              Màn chào mừng thẻ dùng policy nên hai ô này chỉ làm rối:
                              tên hiện ở tóm tắt, còn ngày bắt đầu lấy theo kỳ sao kê.
                              Giá trị vẫn nằm trong state nên payload không đổi.
      $redirectUrl     ?string URL quay về sau khi lưu (nút Hủy + fallback khi API trả 200).

    ---------------------------------------------------------------------------
    CẤU TRÚC CANONICAL
    ---------------------------------------------------------------------------
    `tiers[].rules` ở MỌI nơi — presenter xuất `rules`, editor giữ state `tier.rules` và
    payload gửi `rules`. KHÔNG có `tier.categories` (bug cũ rules ↔ categories đã từng
    xoá sạch rule khi lưu). Giới hạn hoàn tiền theo giá trị giao dịch nằm ở
    `tiers[].transaction_caps` (tài sản của BẬC) — KHÔNG còn ở rule.
    Trần hoàn mỗi kỳ nằm ở TỪNG BẬC (`tiers[].max_cashback_per_period`).

    KHÔNG nhập tiền hoàn "bên trái" ở đây: bậc/tỷ lệ/cap là CẤU HÌNH, tiền thật do
    hệ thống tính khi giao dịch vào sổ.

    ---------------------------------------------------------------------------
    TARGET ĐÃ ẨN
    ---------------------------------------------------------------------------
      Rule cũ trỏ category/combo đã bị ẩn vẫn render đủ (nhãn "Danh mục đã ẩn" /
      "Combo đã ẩn") và giữ nguyên `category_id`/`combo_id` trong state — không
      mất dữ liệu khi MỞ form. Nhưng `ValidatesRuleTargets` chỉ chấp nhận target
      đang active, nên LƯU lại policy đó vẫn bị 422. Editor không tự "chữa" bằng
      cách đổi rule sang fallback: làm vậy là âm thầm đổi cấu hình cashback của
      khách. Muốn cho lưu được thì phải nới rule ở tầng nghiệp vụ (grandfather
      target đã tồn tại), không xử lý ở đây.
--}}
@php
    $endpoint = $endpoint ?? '';
    $updateEndpoint = $updateEndpoint ?? null;
    $submitLabel = $submitLabel ?? 'Lưu';
    $categories = $categories ?? [];
    $combos = $combos ?? [];
    $sourceVersionId = $sourceVersionId ?? null;
    $viewMode = $viewMode ?? false;
    $hosted = $hosted ?? false;
    $showTemplateMeta = $showTemplateMeta ?? true;
    $showPolicyMeta = $showPolicyMeta ?? true;
    $redirectUrl = $redirectUrl ?? null;
    $scopePrefix = $scopePrefix ?? ($hosted ? 'policyEditor.' : '');
    $p = $scopePrefix;
    $initial = $initial ?? [
        'name' => '',
        'description' => '',
        'effective_from' => now()->toDateString(),
        'status' => 'published',
        'tiers' => [],
    ];
@endphp

@if ($hosted)
<div class="space-y-4 sm:space-y-5">
@else
<div x-data="systemPolicyEditor(@js($initial), @js($endpoint), @js($categories), @js($sourceVersionId),
@js($updateEndpoint), @js($viewMode), @js($combos))"
     class="space-y-4 sm:space-y-5">
@endif

    <div x-show="{{ $p }}error" class="rounded-2xl bg-rose-50 border border-rose-200 p-4 text-sm text-rose-700"
         role="alert" x-text="{{ $p }}error"></div>

    {{-- Metadata chính sách. Ẩn trọn khối khi màn cha không cần ô nào. --}}
    @if ($showPolicyMeta || $showTemplateMeta)
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-3">
        <h3 class="font-semibold text-gray-800 text-sm">Thông tin chính sách</h3>

        <div class="grid gap-3 sm:grid-cols-2">
            @if ($showPolicyMeta)
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700" for="cc-sp-name">Tên chính sách</label>
                <input id="cc-sp-name" type="text" x-model="{{ $p }}meta.name" maxlength="150" required :disabled="{{ $p }}viewMode"
                       placeholder="Ví dụ: MB JCB Ultimate"
                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
            </div>
            @endif
            @if ($showTemplateMeta)
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700" for="cc-sp-desc">Mô tả</label>
                <textarea id="cc-sp-desc" x-model="{{ $p }}meta.description" rows="2" maxlength="1000" :disabled="{{ $p }}viewMode"
                          class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500"></textarea>
            </div>
            @endif
            @if ($showPolicyMeta)
            <div>
                <label class="block text-sm font-medium text-gray-700" for="cc-sp-from">Ngày bắt đầu hiệu lực</label>
                <input id="cc-sp-from" type="date" x-model="{{ $p }}meta.effective_from" :disabled="{{ $p }}viewMode"
                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
            </div>
            @endif
            @if ($showTemplateMeta)
            <div>
                <label class="block text-sm font-medium text-gray-700" for="cc-sp-status">Trạng thái</label>
                <select id="cc-sp-status" x-model="{{ $p }}meta.status" :disabled="{{ $p }}viewMode"
                        class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                    <option value="draft">Nháp</option>
                    <option value="published">Đang sử dụng</option>
                    <option value="archived">Lưu trữ</option>
                </select>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Bậc chi tiêu & quy tắc --}}
    <div class="space-y-4 sm:space-y-5">
        <div class="flex items-center justify-between">
            <h3 class="font-semibold text-gray-800 text-sm">Bậc chi tiêu & quy tắc hoàn tiền</h3>
            <button type="button" @click="{{ $p }}addTier()" x-show="!{{ $p }}viewMode"
                    class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 border border-emerald-200 hover:bg-emerald-50">
                + Thêm bậc chi tiêu
            </button>
        </div>

        <p class="text-xs text-gray-500">
            Bậc dùng ngưỡng TỔNG chi tiêu cả kỳ (retrospective) — không có chế độ tính
            dồn từng giao dịch. Đây là <strong>cấu hình</strong>; số tiền hoàn thực tế do
            hệ thống tính khi giao dịch vào sổ.
        </p>

        <template x-for="(tier, ti) in {{ $p }}tiers" :key="ti">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 class="font-semibold text-gray-800 text-sm">Bậc chi tiêu <span x-text="ti + 1"></span></h4>
                    <button type="button" @click="{{ $p }}removeTier(ti)" x-show="!{{ $p }}viewMode"
                            class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-rose-600 border border-rose-200 hover:bg-rose-50">
                        Xoá bậc
                    </button>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="min-w-0">
                        <label class="block text-sm font-medium text-gray-700" :for="`cc-t${ti}-name`">Tên bậc</label>
                        <input :id="`cc-t${ti}-name`" type="text" x-model="tier.name" maxlength="150" :disabled="{{ $p }}viewMode"
                               placeholder="Ví dụ: Chi tiêu cơ bản"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                    </div>
                    <div class="min-w-0">
                        <label class="block text-sm font-medium text-gray-700" :for="`cc-t${ti}-min`">Tổng chi tiêu tối thiểu</label>
                        <input :id="`cc-t${ti}-min`" type="number" min="0" step="0.01" x-model="tier.min_total_spend" :disabled="{{ $p }}viewMode"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                        <p class="mt-1 text-xs text-gray-500">Mức chi tiêu tối thiểu để đạt bậc này.</p>
                    </div>
                    <div class="min-w-0">
                        <label class="block text-sm font-medium text-gray-700" :for="`cc-t${ti}-max`">Tổng chi tiêu tối đa</label>
                        <input :id="`cc-t${ti}-max`" type="number" min="0" step="0.01" x-model="tier.max_total_spend" :disabled="{{ $p }}viewMode"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                        <p class="mt-1 text-xs text-gray-500">Mức chi tiêu cao nhất thuộc bậc này. Để trống nếu không giới hạn.</p>
                    </div>
                    <div class="min-w-0">
                        <label class="block text-sm font-medium text-gray-700" :for="`cc-t${ti}-cap`">Hoàn tiền tối đa của bậc / kỳ</label>
                        <input :id="`cc-t${ti}-cap`" type="number" min="0" step="0.01" x-model="tier.max_cashback_per_period" :disabled="{{ $p }}viewMode"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                        <p class="mt-1 text-xs text-gray-500">Tổng số tiền hoàn tối đa của bậc này trong một kỳ. Để trống nếu không giới hạn.</p>
                    </div>
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" x-model="tier.use_transaction_caps" :disabled="{{ $p }}viewMode"
                           @change="{{ $p }}onTransactionCapsToggle(tier)"
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
                            <button type="button" @click="{{ $p }}addTransactionCap(tier)" x-show="!{{ $p }}viewMode"
                                    class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 border border-emerald-200 hover:bg-emerald-50">
                                + Thêm khoảng
                            </button>
                        </div>

                        <template x-for="(cap, ci) in tier.transaction_caps" :key="ci">
                            <div class="grid gap-2 grid-cols-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                                <div class="min-w-0">
                                    <label class="block text-xs font-medium text-gray-600" :for="`cc-t${ti}-tc${ci}-min`">Từ</label>
                                    <input :id="`cc-t${ti}-tc${ci}-min`" type="number" min="0" step="0.01" x-model="cap.min_transaction_amount" :disabled="{{ $p }}viewMode"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                </div>
                                <div class="min-w-0">
                                    <label class="block text-xs font-medium text-gray-600" :for="`cc-t${ti}-tc${ci}-max`">Đến</label>
                                    <input :id="`cc-t${ti}-tc${ci}-max`" type="number" min="0" step="0.01" x-model="cap.max_transaction_amount" :disabled="{{ $p }}viewMode"
                                           placeholder="Không giới hạn"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                </div>
                                <div class="min-w-0">
                                    <label class="block text-xs font-medium text-gray-600" :for="`cc-t${ti}-tc${ci}-cap`">Hoàn tối đa / giao dịch</label>
                                    <input :id="`cc-t${ti}-tc${ci}-cap`" type="number" min="0" step="0.01" x-model="cap.max_cashback_per_transaction" :disabled="{{ $p }}viewMode"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                </div>
                                <button type="button" @click="{{ $p }}removeTransactionCap(tier, ci)" x-show="!{{ $p }}viewMode"
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
                                    <span x-show="rule.target_scope === 'other'"
                                          class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700 normal-case">
                                        📦 Các danh mục còn lại
                                    </span>
                                    <span x-show="rule.target_scope === 'combo'"
                                          class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 normal-case">
                                        🍱 <span x-text="{{ $p }}comboLabel(rule.combo_id)"></span>
                                    </span>
                                </p>
                                <button type="button" @click="{{ $p }}removeRule(tier, ri)" x-show="!{{ $p }}viewMode"
                                        class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-rose-600 border border-rose-200 hover:bg-rose-50">
                                    Xoá
                                </button>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                {{-- "Phạm vi danh mục" = 3 TRẠNG THÁI target của rule.
                                     Ưu tiên: danh mục cụ thể > combo > danh mục còn lại. --}}
                                <div class="min-w-0">
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-scope`">Phạm vi danh mục</label>
                                    <select :id="`cc-r${ti}-${ri}-scope`" x-model="rule.target_scope" @change="{{ $p }}onTargetScopeChange(tier, rule)" :disabled="{{ $p }}viewMode"
                                            class="mt-1 block w-full min-h-[44px] rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                        <option value="category">Danh mục cụ thể</option>
                                        <option value="combo">🍱 Combo danh mục</option>
                                        <option value="other">📦 Danh mục còn lại</option>
                                    </select>
                                </div>
                                <div class="min-w-0" x-show="rule.target_scope === 'category'">
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-cat`">Danh mục</label>
                                    <select :id="`cc-r${ti}-${ri}-cat`" x-model="rule.category_id" :disabled="{{ $p }}viewMode"
                                            x-init="$nextTick(() => { $el.value = rule.category_id ?? ''; })"
                                            class="mt-1 block w-full min-h-[44px] rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                        <option value="">— Chọn danh mục —</option>
                                        <template x-for="cat in {{ $p }}categories" :key="cat.id">
                                            <option :value="cat.id" x-text="cat.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="min-w-0" x-show="rule.target_scope === 'combo'" x-cloak>
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-combo`">Combo</label>
                                    <select :id="`cc-r${ti}-${ri}-combo`" x-model="rule.combo_id" :disabled="{{ $p }}viewMode"
                                            x-init="$nextTick(() => { $el.value = rule.combo_id ?? ''; })"
                                            class="mt-1 block w-full min-h-[44px] rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                        <option value="">— Chọn combo —</option>
                                        <template x-for="combo in {{ $p }}combos" :key="combo.id">
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
                                <div class="min-w-0 sm:col-span-1" x-show="rule.target_scope === 'other'">
                                    <label class="block text-sm font-medium text-gray-700">&nbsp;</label>
                                    <p class="mt-1 text-xs text-gray-500 leading-relaxed">
                                        Quy tắc mặc định áp cho mọi danh mục chưa có quy tắc cụ thể
                                        trong bậc này. Mỗi bậc chỉ có <strong>một</strong> quy tắc này.
                                    </p>
                                </div>
                                <div class="min-w-0">
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-pct`">Hoàn tiền (%)</label>
                                    <input :id="`cc-r${ti}-${ri}-pct`" type="number" min="0" max="100" step="0.001" x-model="rule.cashback_percent" :disabled="{{ $p }}viewMode"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                </div>
                                <div class="min-w-0">
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-cap-tx`">Hoàn tối đa / giao dịch</label>
                                    <input :id="`cc-r${ti}-${ri}-cap-tx`" type="number" min="0" step="0.01" x-model="rule.max_cashback_per_transaction" :disabled="{{ $p }}viewMode"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                    <p class="mt-1 text-xs text-gray-500">Số tiền hoàn cao nhất cho một giao dịch.</p>
                                </div>
                                <div class="min-w-0">
                                    <label class="block text-sm font-medium text-gray-700" :for="`cc-r${ti}-${ri}-cap-cat`">Hoàn tối đa / danh mục</label>
                                    <input :id="`cc-r${ti}-${ri}-cap-cat`" type="number" min="0" step="0.01" x-model="rule.max_cashback_per_category_per_period" :disabled="{{ $p }}viewMode"
                                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-500">
                                    <p class="mt-1 text-xs text-gray-500">Tổng số tiền hoàn cao nhất của danh mục trong một kỳ sao kê.</p>
                                </div>
                            </div>

                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" x-model="rule.counts_toward_tier_cap" :disabled="{{ $p }}viewMode"
                                       class="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 disabled:bg-gray-50">
                                <span>
                                    <span class="font-medium text-gray-700">Tính vào giới hạn hoàn tiền của bậc</span>
                                    <span class="block text-xs text-gray-500">Bật nếu hoàn tiền của quy tắc này tiêu tốn trần
                                    “Hoàn tiền tối đa của bậc / kỳ”.</span>
                                </span>
                            </label>

                            {{-- "Tính hạn mức chi tiêu còn lại" — KHÁC hẳn cờ trên:
                                 - cờ trên là cấu hình ENGINE (cap bậc), engine đọc;
                                 - cờ này là cấu hình QUOTA, chỉ dùng để báo "còn chi
                                   thêm được bao nhiêu" ở Tổng quan.
                                 Fallback không có mục tiêu chi tiêu cụ thể nên ẩn, và
                                 state ép false để không bao giờ gửi tick lên fallback. --}}
                            <template x-if="rule.target_scope !== 'other'">
                                <label class="flex items-start gap-2 text-sm rounded-xl bg-white/70 p-2.5 border border-gray-100">
                                    <input type="checkbox" x-model="rule.is_quota_category" :disabled="{{ $p }}viewMode"
                                           @change="{{ $p }}onQuotaCategoryToggle(rule)"
                                           class="mt-0.5 h-5 w-5 shrink-0 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 disabled:bg-gray-50">
                                    <span class="min-w-0">
                                        <span class="font-medium text-gray-700">Tính hạn mức chi tiêu còn lại</span>
                                        <span class="block text-xs text-gray-500">Dùng danh mục này để tính số tiền bạn còn có thể chi thêm
                                        để nhận tối đa hoàn tiền.</span>
                                    </span>
                                </label>
                            </template>
                        </div>
                    </template>

                    <div x-show="tier.rules.length === 0"
                         class="rounded-xl bg-white border border-dashed border-gray-300 p-4 text-center text-xs text-gray-400">
                        Bậc này chưa có quy tắc nào.
                    </div>
                </div>

                <button type="button" @click="{{ $p }}addRule(tier)" x-show="!{{ $p }}viewMode"
                        class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 border border-emerald-200 hover:bg-emerald-50">
                    + Thêm quy tắc cashback
                </button>

                {{-- Bất biến "mọi quy tắc tính hạn mức phải cùng một bậc". Backend
                     chặn (422), nhưng cảnh báo ngay tại ô nhập để admin không phải
                     submit lên rồi mới biết. --}}
                <p class="text-xs text-gray-500">
                    Các quy tắc được chọn “Tính hạn mức chi tiêu còn lại”
                    <strong>phải cùng thuộc một bậc</strong> trong chính sách này — chỉ một bậc được tính hạn mức
                    (bậc ứng với mục tiêu chi tiêu của người dùng).
                </p>
            </div>
        </template>

        <div x-show="{{ $p }}tiers.length === 0"
             class="rounded-2xl bg-gray-50 border border-dashed border-gray-300 p-4 text-sm text-gray-500">
            Chưa có bậc chi tiêu nào. Bấm "+ Thêm bậc chi tiêu" để bắt đầu.
        </div>
    </div>

    {{-- Hành động. Ở chế độ HOSTED (form thẻ) KHÔNG có: nút "Lưu thẻ" của form
         cha là nút lưu duy nhất, tránh hai nút cùng ghi một chính sách. --}}
    @if (! $viewMode && ! $hosted)
    <template x-if="updateEndpoint">
        <div class="flex flex-col gap-2 sm:flex-row">
            <button type="button" @click="{{ $p }}submit('current')" :disabled="{{ $p }}busy"
                    class="rounded-xl bg-emerald-600 px-4 py-2.5 min-h-[44px] text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 w-full sm:w-auto">
                Lưu lại
            </button>
            <button type="button" @click="{{ $p }}submit('new')" :disabled="{{ $p }}busy"
                    class="rounded-xl bg-white px-4 py-2.5 min-h-[44px] text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50 w-full sm:w-auto">
                Lưu phiên bản mới
            </button>
            <a href="{{ $redirectUrl ?? route('admin.credit-card-policies.index') }}"
               class="rounded-xl bg-white px-4 py-2.5 min-h-[44px] text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50 text-center w-full sm:w-auto">
                Hủy
            </a>
        </div>
    </template>
    <template x-if="!updateEndpoint">
        <div class="flex flex-col gap-2 sm:flex-row">
            <button type="button" @click="{{ $p }}submit('new')" :disabled="{{ $p }}busy"
                    class="rounded-xl bg-emerald-600 px-4 py-2.5 min-h-[44px] text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 w-full sm:w-auto">
                {{ $submitLabel }}
            </button>
            <a href="{{ $redirectUrl ?? route('admin.credit-card-policies.index') }}"
               class="rounded-xl bg-white px-4 py-2.5 min-h-[44px] text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50 text-center w-full sm:w-auto">
                Hủy
            </a>
        </div>
    </template>
    @endif
</div>

@push('scripts')
<script>
    /**
     * Thông báo bất biến "mọi quy tắc tính hạn mức phải cùng một bậc".
     *
     * Đặt ở `window` để dùng CHUNG ở mọi bản hiển thị của partial (partial này
     * được include 4 lần: create / edit / show / form thẻ) — khai báo trùng tên
     * trong các scope khác nhau là hợp lệ, nhưng một hằng số là đủ và luôn khớp
     * với hằng số `CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE` mà backend trả
     * về. Test khoá đúng câu này.
     */
    window.CC_QUOTA_TIER_MESSAGE = @js(\App\Services\CreditCard\CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE);

    /**
     * Suy "Phạm vi danh mục" (giá trị của select) từ dữ liệu rule server trả về.
     * Presenter xuất `scope_type` + `target_type`; rule cũ chỉ có `category_id` thì
     * suy tiếp từ `combo_id` để không mất nhãn.
     *
     * BẤT BIẾN TARGET: rule COMBO vẫn mang `scope_type = category` (xem
     * `ValidatesRuleTargets`), nên combo KHÔNG nhận diện được qua `scope_type` —
     * phải nhìn `combo_id`. `scope_type` chỉ phân biệt "còn lại" với "có điều kiện".
     */
    function ruleTargetScope(rule) {
        if ((rule.scope_type ?? 'category') === 'other') {
            return 'other';
        }

        const kind = rule.target_type ?? ((rule.combo_id ?? null) !== null ? 'combo' : 'category');

        return kind === 'combo' ? 'combo' : 'category';
    }

    /**
     * STATE + PAYLOAD CỦA POLICY EDITOR — nguồn sự thật DUY NHẤT cho mọi màn hình.
     *
     * Tách riêng khỏi `systemPolicyEditor()` vì form Thẻ cần đúng phần này mà KHÔNG
     * có nút lưu/endpoint riêng: nó gọi thẳng `policyEditorState()` để dựng state
     * ngay trong component cha, rồi render lại CHÍNH partial này ở chế độ hosted.
     * Nhờ vậy không tồn tại hai bộ chuyển đổi payload — sửa bug ở đây là sửa cả hai
     * nơi.
     *
     * @param initial    array  metadata + tiers từ presenter
     * @param categories array  danh mục user được chọn
     * @param combos     array  combo user được chọn (kèm `category_count`)
     * @param viewMode   bool   khoá toàn bộ ô nhập
     */
    window.policyEditorState = function (initial, categories, combos, viewMode) {
        return {
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
                            // "Phạm vi danh mục" gộp cả 3 trạng thái target nên là nguồn
                            // sự thật duy nhất: presenter xuất `scope_type` + `target_type`,
                            // rule cũ chỉ có `category_id` vẫn mặc định "Danh mục cụ thể".
                            target_scope: ruleTargetScope(rule),
                            counts_toward_tier_cap: rule.scope_type === 'other'
                                ? (rule.counts_toward_tier_cap ?? false)
                                : (rule.counts_toward_tier_cap ?? true),
                            is_quota_category: rule.is_quota_category === true,
                            category_id: rule.category_id ?? '',
                            combo_id: rule.combo_id ?? '',
                            name: rule.name ?? null,
                            // Không có ô nhập cho các cờ dưới đây trong UI, nhưng BẮT BUỘC
                            // phải mang qua payload: lưu thẻ tạo version MỚI bằng
                            // `replaceChildren()` (xoá rồi tạo lại dòng), nên thiếu chúng
                            // là mất cấu hình cũ. Mang theo ⇒ mở lại vẫn y nguyên.
                            spend_from: rule.spend_from ?? 0,
                            spend_to: rule.spend_to ?? '',
                            is_enabled: rule.is_enabled !== false,
                            note: rule.note ?? null,
                            cashback_percent: rule.cashback_percent ?? 0,
                            max_cashback_per_transaction: rule.max_cashback_per_transaction ?? '',
                            max_cashback_per_category_per_period: rule.max_cashback_per_category_per_period ?? '',
                            min_transaction_amount: rule.min_transaction_amount ?? '',
                        })),
                    };
                })
                : [],
            categories: categories ?? [],
            combos: combos ?? [],
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
                    target_scope: 'category',
                    counts_toward_tier_cap: true,
                    is_quota_category: false,
                    category_id: '',
                    combo_id: '',
                    name: null,
                    spend_from: 0,
                    spend_to: '',
                    is_enabled: true,
                    note: null,
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

                if (rule && rule.target_scope === 'other') {
                    this.error = "Quy tắc '📦 Danh mục còn lại' là quy tắc mặc định của mỗi bậc và không thể xóa.";
                    return;
                }

                tier.rules.splice(idx, 1);
            },

            // Đổi "Phạm vi danh mục" giữa 3 trạng thái: danh mục cụ thể | combo | còn lại.
            // Mỗi lần đổi chỉ giữ lại target của trạng thái mới để payload KHÔNG BAO GIỜ
            // mang cả `category_id` lẫn `combo_id` (server 422 khi loại trừ lẫn nhau).
            onTargetScopeChange(tier, rule) {
                if (rule.target_scope === 'other') {
                    if (tier.rules.some((other) => other !== rule && other.target_scope === 'other')) {
                        this.error = "Chỉ được có một quy tắc '📦 Danh mục còn lại' trong mỗi bậc.";
                        rule.target_scope = 'category';
                        return;
                    }

                    rule.counts_toward_tier_cap = false;
                    // Fallback không phải một danh mục cụ thể ⇒ không có hạn mức nào
                    // để tính. Ép false để payload không bao giờ mang cờ lên fallback.
                    rule.is_quota_category = false;
                    // Fallback không mang target: service ép null cả hai, editor gửi
                    // null luôn để payload không mang id mồ côi.
                    rule.category_id = '';
                    rule.combo_id = '';
                    return;
                }

                if (rule.target_scope === 'combo') {
                    rule.category_id = '';
                } else {
                    rule.combo_id = '';
                }
            },

            /**
             * Bật/tắt "Tính hạn mức chi tiêu còn lại".
             *
             * Bất biến nghiệp vụ: trong MỘT policy version, các quy tắc được tick
             * phải cùng thuộc MỘT bậc (quota chỉ xác định một bậc rồi đọc các quy
             * tắc được tick của bậc đó). Ở đây chỉ CẢNH BÁO SỚM và bỏ tick để lưu
             * được — backend `CategoryRuleService` mới là nơi bắt buộc, vì editor là
             * UI còn client/script có thể gửi payload bất kỳ.
             */
            onQuotaCategoryToggle(rule) {
                if (!rule.is_quota_category) {
                    return;
                }

                if (this.quotaCategoryTierCount() > 1) {
                    rule.is_quota_category = false;
                    this.error = window.CC_QUOTA_TIER_MESSAGE;
                }
            },

            /**
             * Số bậc đang có ít nhất một quy tắc được tick.
             */
            quotaCategoryTierCount() {
                return this.tiers.filter((tier) => (tier.rules ?? []).some((rule) => rule.is_quota_category === true)).length;
            },

            // Combo đã ẩn vẫn có trong danh sách để không mất nhãn rule cũ.
            comboLabel(comboId) {
                if (comboId === null || comboId === '') {
                    return 'Combo';
                }

                const found = this.combos.find((combo) => String(combo.id) === String(comboId));

                return found ? found.name : 'Combo đã ẩn';
            },

            // Nhãn danh mục cho TÓM TẮT của form Thẻ. Danh mục ẩn không nằm trong
            // danh sách chọn được nên phải nói rõ thay vì hiện tên rỗng.
            categoryLabel(categoryId) {
                if (categoryId === null || categoryId === '') {
                    return 'Danh mục';
                }

                const found = this.categories.find((category) => String(category.id) === String(categoryId));

                return found ? found.name : 'Danh mục đã ẩn';
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

            // Cấu hình thuộc VERSION — thân của "Lưu lại" (PATCH versions.update) và
            // cũng là phần `tiers` mà form Thẻ gửi kèm. Một hàm, hai nơi dùng.
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
                        rules: (tier.rules ?? []).map((rule, ri) => {
                            // "Phạm vi danh mục" quyết định trạng thái payload: fallback
                            // (`scope_type=other`) ⇒ cả hai target null; còn lại chỉ MỘT
                            // trong category_id / combo_id được gửi.
                            const kind = rule.target_scope ?? 'category';
                            const isFallback = kind === 'other';
                            const useCombo = kind === 'combo';

                            return {
                                id: rule.id ?? null,
                                scope_type: isFallback ? 'other' : 'category',
                                counts_toward_tier_cap: rule.counts_toward_tier_cap ?? (isFallback ? false : true),
                                // Fallback không bao giờ tính hạn mức — gửi false để
                                // service không phải chặn một payload vô hại.
                                is_quota_category: !isFallback && rule.is_quota_category === true,
                                category_id: isFallback || useCombo ? null : this.targetId(rule.category_id),
                                combo_id: isFallback || ! useCombo ? null : this.targetId(rule.combo_id),
                                name: rule.name ?? null,
                                // Cờ không có ô nhập vẫn phải đi kèm — xem giải thích ở state.
                                spend_from: this.num(rule.spend_from) ?? 0,
                                spend_to: this.num(rule.spend_to),
                                is_enabled: rule.is_enabled !== false,
                                note: rule.note ?? null,
                                cashback_percent: this.num(rule.cashback_percent),
                                max_cashback_per_transaction: this.num(rule.max_cashback_per_transaction),
                                max_cashback_per_category_per_period: this.num(rule.max_cashback_per_category_per_period),
                                min_transaction_amount: this.num(rule.min_transaction_amount),
                                sort_order: ri + 1,
                            };
                        }),
                    })),
                };
            },
        };
    };

    /**
     * Editor System Policy: state chung + vỏ giao tiếp với API (nút lưu, redirect).
     * Form Thẻ KHÔNG dùng lớp này — nó dùng `policyEditorState()` và tự lưu.
     */
    window.systemPolicyEditor = function (initial, endpoint, categories, sourceVersionId, updateEndpoint, viewMode, combos) {
        return {
            ...window.policyEditorState(initial, categories, combos, viewMode),
            endpoint: endpoint ?? '',
            sourceVersionId: sourceVersionId ?? null,
            updateEndpoint: updateEndpoint ?? null,

            // Metadata template (vỏ ngoài). Gửi kèm cho CẢ "Lưu lại" lẫn "Lưu phiên
            // bản mới" để tên/mô tả/trạng thái đã nhập không bị mất khi bấm Lưu lại.
            templateMeta() {
                return {
                    name: this.meta.name,
                    description: this.meta.description === '' ? null : this.meta.description,
                    status: this.meta.status,
                };
            },

            // Payload cho "Lưu lại" (PATCH versions.update): cấu hình version + metadata
            // template. KHÔNG có `source_version_id` vì không tạo version mới.
            currentPayload() {
                return {
                    ...this.versionConfig(),
                    ...this.templateMeta(),
                };
            },

            // Payload đầy đủ cho "Lưu phiên bản mới" / tạo mới: metadata template +
            // cấu hình version. Nguồn copy để và giữ id NGHĨA LÀ SỬA; bấm nút này luôn
            // TẠO phiên bản kế tiếp, không được lẫn với "Lưu lại".
            payload() {
                const payload = {
                    ...this.versionConfig(),
                    ...this.templateMeta(),
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
                const url = isCurrent ? this.updateEndpoint : this.endpoint;

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
                        body: JSON.stringify(isCurrent ? this.currentPayload() : this.payload()),
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
                    window.location.href = data?.redirect || @js($redirectUrl ?? route('admin.credit-card-policies.index'));
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
