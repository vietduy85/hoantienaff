{{--
    /thetindung/danh-muc — Danh mục chi tiêu (quản lý nhóm chi tiêu thực tế).

    - "🏦 DANH MỤC HỆ THỐNG": 19 nhóm chi tiêu hệ thống, chỉ đọc.
    - "👤 DANH MỤC CỦA TÔI": chi tiêu riêng do user tạo, CRUD đầy đủ.

    Mọi dữ liệu lấy từ JSON API cùng origin (`/thetindung/api/danh-muc`) —
    một đường đọc duy nhất, tránh render chênh lệch với API.
--}}
<x-credit-card.layout
    title="Danh mục chi tiêu"
    subtitle="Quản lý các nhóm chi tiêu dùng cho thẻ tín dụng và hoàn tiền"
    active="categories">

    <div x-data="categoryManager()"
         x-init="init()"
         class="space-y-4 sm:space-y-5">

        {{-- Thanh công cụ: tìm kiếm + thêm mới --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 flex flex-col gap-3">
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400" aria-hidden="true">🔎</span>
                <input id="cc-category-search"
                       type="search"
                       x-model="search"
                       placeholder="Tìm danh mục hoặc combo theo tên…"
                       class="w-full rounded-xl border-gray-300 pl-10 pr-4 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <p class="text-xs text-gray-500">
                Danh mục hệ thống do quản trị viên quản lý. Bạn có thể tạo danh mục riêng để theo dõi chi tiêu theo nhu cầu,
                rồi gom nhiều danh mục thành <strong>combo</strong> để áp hoàn tiền cho cả nhóm.
            </p>
        </div>

        {{-- Thông báo --}}
        <template x-if="notice">
            <div class="rounded-2xl bg-emerald-50 border border-emerald-200 p-4 text-sm text-emerald-800"
                 role="status" x-text="notice"></div>
        </template>

        <template x-if="error">
            <div class="rounded-2xl bg-rose-50 border border-rose-200 p-4 text-sm text-rose-700"
                 role="alert" x-text="error"></div>
        </template>

        <template x-if="loading">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 text-sm text-gray-500">
                Đang tải danh mục…
            </div>
        </template>

        <template x-if="!loading && error === ''">
            <div class="space-y-4 sm:space-y-5">

                {{-- ===== DANH MỤC HỆ THỐNG ===== --}}
                <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <header class="flex items-center justify-between gap-3 px-4 sm:px-5 py-3.5 border-b border-gray-100">
                        <h3 class="font-semibold text-gray-800 text-sm">
                            🏦 <span class="ml-1">DANH MỤC HỆ THỐNG</span>
                            <span x-text="`(${filteredSystem.length})`"
                                  class="ml-1 font-medium text-gray-400"></span>
                        </h3>
                    </header>

                    <template x-if="filteredSystem.length === 0">
                        <p class="px-4 sm:px-5 py-5 text-sm text-gray-500">Không tìm thấy danh mục hệ thống nào.</p>
                    </template>

                    <ul class="divide-y divide-gray-100">
                        <template x-for="category in filteredSystem" :key="category.id">
                            <li class="flex items-start gap-3 px-4 sm:px-5 py-3">
                                <span class="text-lg leading-none mt-0.5" aria-hidden="true" x-text="category.icon"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-800" x-text="category.name"></p>
                                    <p class="text-xs text-gray-500 mt-0.5 line-clamp-2" x-show="category.description" x-text="category.description"></p>
                                </div>
                                <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-[11px] font-semibold text-gray-600">
                                    Hệ thống
                                </span>
                            </li>
                        </template>
                    </ul>
                </section>

                {{-- ===== DANH MỤC CỦA TÔI ===== --}}
                <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <header class="flex items-center justify-between gap-3 px-4 sm:px-5 py-3.5 border-b border-gray-100">
                        <h3 class="font-semibold text-gray-800 text-sm">
                            👤 <span class="ml-1">DANH MỤC CỦA TÔI</span>
                            <span x-text="`(${mine.length})`"
                                  class="ml-1 font-medium text-gray-400"></span>
                        </h3>
                        <button type="button"
                                @click="openCreate()"
                                class="shrink-0 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                            + Thêm danh mục
                        </button>
                    </header>

                    <template x-if="mine.length === 0">
                        <div class="px-4 sm:px-5 py-10 text-center">
                            <p class="text-3xl" aria-hidden="true">📂</p>
                            <p class="mt-2 text-sm font-medium text-gray-700">Bạn chưa tạo danh mục chi tiêu riêng.</p>
                            <p class="mt-1 text-xs text-gray-500">Tạo danh mục để cá nhân hóa cách theo dõi chi tiêu của bạn.</p>
                            <button type="button"
                                    @click="openCreate()"
                                    class="mt-4 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                + Thêm danh mục
                            </button>
                        </div>
                    </template>

                    <template x-if="mine.length > 0 && filteredMine.length === 0">
                        <p class="px-4 sm:px-5 py-5 text-sm text-gray-500">Không tìm thấy danh mục nào khớp từ khoá tìm kiếm.</p>
                    </template>

                    <ul class="divide-y divide-gray-100">
                        <template x-for="category in filteredMine" :key="category.id">
                            <li class="flex items-start gap-3 px-4 sm:px-5 py-3">
                                <span class="text-lg leading-none mt-0.5" aria-hidden="true" x-text="category.icon"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-800" x-text="category.name"></p>
                                    <p class="text-xs text-gray-500 mt-0.5 line-clamp-2" x-show="category.description" x-text="category.description"></p>
                                    <div class="flex flex-wrap gap-1.5 mt-1.5">
                                        <span class="rounded-full bg-blue-50 px-2.5 py-0.5 text-[11px] font-semibold text-blue-700">
                                            Riêng tôi
                                        </span>
                                        <span x-show="!category.is_active"
                                              class="rounded-full bg-rose-50 px-2.5 py-0.5 text-[11px] font-semibold text-rose-600">
                                            Đã ẩn
                                        </span>
                                    </div>
                                </div>
                                <div class="shrink-0 flex items-center gap-1">
                                    <button type="button"
                                            @click="openEdit(category)"
                                            :disabled="busy"
                                            title="Sửa danh mục"
                                            class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-800 disabled:opacity-50">
                                        ✏️
                                    </button>
                                    <button type="button"
                                            @click="remove(category)"
                                            :disabled="busy"
                                            title="Xoá danh mục"
                                            class="rounded-lg p-2 text-gray-500 hover:bg-rose-50 hover:text-rose-600 disabled:opacity-50">
                                        🗑️
                                    </button>
                                </div>
                            </li>
                        </template>
                    </ul>
                </section>

                {{-- ===== COMBO DANH MỤC CỦA TÔI ===== --}}
                <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <header class="flex items-center justify-between gap-3 px-4 sm:px-5 py-3.5 border-b border-gray-100">
                        <h3 class="font-semibold text-gray-800 text-sm">
                            🍱 <span class="ml-1">COMBO DANH MỤC</span>
                            <span x-text="`(${myCombos.length})`"
                                  class="ml-1 font-medium text-gray-400"></span>
                        </h3>
                        <button type="button"
                                @click="openComboCreate()"
                                class="shrink-0 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                            + Tạo combo
                        </button>
                    </header>

                    <p class="px-4 sm:px-5 py-3 text-xs text-gray-500 border-b border-gray-100">
                        Combo gom nhiều danh mục thành một nhóm, để chính sách hoàn tiền gắn
                        <em>một tỷ lệ cho cả combo</em> thay vì gắn từng danh mục.
                    </p>

                    {{-- Combo hệ thống: chỉ đọc --}}
                    <template x-if="systemCombos.length > 0">
                        <div class="border-b border-gray-100">
                            <p class="px-4 sm:px-5 pt-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                Combo hệ thống
                            </p>
                            <ul class="divide-y divide-gray-50">
                                <template x-for="combo in filteredSystemCombos" :key="'sys-' + combo.id">
                                    <li class="flex items-start gap-3 px-4 sm:px-5 py-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium text-gray-800" x-text="combo.name"></p>
                                            <p class="text-xs text-gray-500 mt-0.5 line-clamp-2" x-show="combo.description" x-text="combo.description"></p>
                                            <p class="text-[11px] text-gray-400 mt-1" x-text="memberLabel(combo)"></p>
                                        </div>
                                        <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-[11px] font-semibold text-gray-600">
                                            Hệ thống
                                        </span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <template x-if="myCombos.length === 0">
                        <div class="px-4 sm:px-5 py-10 text-center">
                            <p class="text-3xl" aria-hidden="true">🍱</p>
                            <p class="mt-2 text-sm font-medium text-gray-700">Bạn chưa tạo combo nào.</p>
                            <p class="mt-1 text-xs text-gray-500">Gom các danh mục bạn hay chi tiêu vào một combo để dễ áp hoàn tiền.</p>
                            <button type="button"
                                    @click="openComboCreate()"
                                    class="mt-4 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                + Tạo combo
                            </button>
                        </div>
                    </template>

                    <template x-if="myCombos.length > 0">
                        <ul class="divide-y divide-gray-100">
                            <template x-for="combo in filteredMyCombos" :key="'mine-' + combo.id">
                                <li class="flex items-start gap-3 px-4 sm:px-5 py-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-800" x-text="combo.name"></p>
                                        <p class="text-xs text-gray-500 mt-0.5 line-clamp-2" x-show="combo.description" x-text="combo.description"></p>
                                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                                            <span class="rounded-full bg-blue-50 px-2.5 py-0.5 text-[11px] font-semibold text-blue-700">
                                                <span x-text="`${combo.items.length} danh mục`"></span>
                                            </span>
                                            <span x-show="!combo.is_active"
                                                  class="rounded-full bg-rose-50 px-2.5 py-0.5 text-[11px] font-semibold text-rose-600">
                                                Đã ẩn
                                            </span>
                                        </div>
                                        <p class="text-[11px] text-gray-400 mt-1" x-text="memberLabel(combo)"></p>
                                    </div>
                                    <button type="button"
                                            @click="openComboEdit(combo)"
                                            :disabled="busy"
                                            title="Sửa combo"
                                            class="shrink-0 rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-800 disabled:opacity-50">
                                        ✏️
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </template>

                    <template x-if="myCombos.length > 0 && filteredMyCombos.length === 0">
                        <p class="px-4 sm:px-5 py-5 text-sm text-gray-500">Không tìm thấy combo nào khớp từ khoá tìm kiếm.</p>
                    </template>
                </section>
            </div>
        </template>

        {{-- Modal tạo / sửa danh mục --}}
        <x-modal name="category-form" :show="false" maxWidth="md">
            <div class="p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <h3 class="font-bold text-gray-800"
                        x-text="form.mode === 'create' ? 'Thêm danh mục chi tiêu' : 'Sửa danh mục chi tiêu'"></h3>
                    <button type="button" @click="closeModal()"
                            class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" aria-label="Đóng">
                        ✕
                    </button>
                </div>

                <template x-if="error">
                    <div class="mb-3 rounded-xl bg-rose-50 border border-rose-200 p-3 text-sm text-rose-700"
                         role="alert" x-text="error"></div>
                </template>

                <form @submit.prevent="save()" class="space-y-4">
                    <div>
                        <label for="cc-cat-name" class="block text-sm font-medium text-gray-700">
                            Tên danh mục <span class="text-rose-500">*</span>
                        </label>
                        <input id="cc-cat-name"
                               type="text"
                               x-model="form.name"
                               maxlength="150"
                               placeholder="Ví dụ: Sửa chữa ô tô"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-gray-500">Tối đa 150 ký tự.</p>
                    </div>

                    <div>
                        <label for="cc-cat-desc" class="block text-sm font-medium text-gray-700">Mô tả</label>
                        <textarea id="cc-cat-desc"
                                  x-model="form.description"
                                  rows="3"
                                  maxlength="1000"
                                  placeholder="Mô tả ngắn về nhóm chi tiêu này (không bắt buộc)"
                                  class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                        <button type="button"
                                @click="closeModal()"
                                class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Huỷ
                        </button>
                        <button type="submit"
                                :disabled="busy"
                                class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                            Lưu danh mục
                        </button>
                    </div>
                </form>
            </div>
        </x-modal>

        {{-- Modal tạo / sửa combo --}}
        <x-modal name="combo-form" :show="false" maxWidth="md">
            <div class="p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <h3 class="font-bold text-gray-800"
                        x-text="comboForm.mode === 'create' ? 'Tạo combo danh mục' : 'Sửa combo danh mục'"></h3>
                    <button type="button" @click="closeComboModal()"
                            class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" aria-label="Đóng">
                        ✕
                    </button>
                </div>

                <template x-if="comboError">
                    <div class="mb-3 rounded-xl bg-rose-50 border border-rose-200 p-3 text-sm text-rose-700"
                         role="alert" x-text="comboError"></div>
                </template>

                <form @submit.prevent="saveCombo()" class="space-y-4">
                    <div>
                        <label for="cc-combo-name" class="block text-sm font-medium text-gray-700">
                            Tên combo <span class="text-rose-500">*</span>
                        </label>
                        <input id="cc-combo-name"
                               type="text"
                               x-model="comboForm.name"
                               maxlength="150"
                               placeholder="Ví dụ: Chi tiêu gia đình"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label for="cc-combo-desc" class="block text-sm font-medium text-gray-700">Mô tả</label>
                        <textarea id="cc-combo-desc"
                                  x-model="comboForm.description"
                                  rows="2"
                                  maxlength="1000"
                                  placeholder="Mô tả ngắn (không bắt buộc)"
                                  class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <label class="block text-sm font-medium text-gray-700">
                                Danh mục thành phần <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[11px] text-gray-500">
                                Đã chọn <span class="font-semibold text-emerald-600" x-text="comboForm.category_ids.length"></span>
                            </span>
                        </div>

                        <input type="search"
                               x-model="comboMemberSearch"
                               placeholder="Lọc danh mục…"
                               class="mt-2 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">

                        <div class="mt-2 max-h-64 overflow-auto rounded-xl border border-gray-200 p-2 divide-y divide-gray-50">
                            <template x-for="category in selectableCategories" :key="category.id">
                                <label class="flex items-start gap-2.5 px-1 py-2.5 min-h-[44px] cursor-pointer">
                                    <input type="checkbox"
                                           :value="category.id"
                                           x-model="comboForm.category_ids"
                                           class="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm text-gray-800" x-text="category.name"></span>
                                        <span class="block text-[11px] text-gray-400"
                                              x-text="category.scope === 'system' ? 'Danh mục hệ thống' : 'Danh mục của tôi'"></span>
                                    </span>
                                </label>
                            </template>
                        </div>

                        <p class="mt-1.5 text-xs text-gray-500">
                            Mỗi danh mục chỉ được chọn một lần. Danh mục đã bị ẩn không chọn được.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                        <button type="button"
                                @click="closeComboModal()"
                                class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Huỷ
                        </button>
                        <button type="submit"
                                :disabled="busy"
                                class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                            Lưu combo
                        </button>
                    </div>
                </form>
            </div>
        </x-modal>
    </div>

    @push('scripts')
        <script>
            window.categoryManager = function () {
                return {
                    system: [],
                    mine: [],
                    systemCombos: [],
                    myCombos: [],
                    search: '',
                    comboMemberSearch: '',
                    loading: false,
                    busy: false,
                    error: '',
                    notice: '',
                    comboError: '',

                    form: { mode: 'create', id: null, name: '', description: '' },
                    comboForm: { mode: 'create', id: null, name: '', description: '', category_ids: [] },

                    init() {
                        this.form = { mode: 'create', id: null, name: '', description: '' };
                        this.comboForm = { mode: 'create', id: null, name: '', description: '', category_ids: [] };
                        this.load();
                    },

                    apiBase() {
                        return '/thetindung/api/danh-muc';
                    },

                    comboApiBase() {
                        return '/thetindung/api/combo';
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

                    async load() {
                        this.loading = true;
                        this.error = '';
                        this.notice = '';
                        this.system = [];
                        this.mine = [];
                        this.systemCombos = [];
                        this.myCombos = [];

                        // Combo và danh mục đi cùng nhau: danh mục là nguồn chọn
                        // thành viên của combo, nên tải chung một lượt cho nhất quán.
                        const [categoryPayload, comboPayload] = await Promise.all([
                            this.request(this.apiBase()),
                            this.request(this.comboApiBase()),
                        ]);

                        this.loading = false;
                        if (!categoryPayload) return;

                        this.system = categoryPayload.system_categories ?? [];
                        this.mine = categoryPayload.user_categories ?? [];

                        if (comboPayload) {
                            this.systemCombos = comboPayload.system_combos ?? [];
                            this.myCombos = comboPayload.user_combos ?? [];
                        }
                    },

                    get filteredSystem() {
                        return this.filterList(this.system);
                    },

                    get filteredMine() {
                        return this.filterList(this.mine);
                    },

                    get filteredSystemCombos() {
                        return this.filterList(this.systemCombos);
                    },

                    get filteredMyCombos() {
                        return this.filterList(this.myCombos);
                    },

                    /**
                     * Danh mục user được chọn làm thành viên combo: danh mục hệ thống
                     * đang bật + danh mục riêng đang bật. Ẩn rồi thì không chọn
                     * được (server cũng chặn lại lần nữa).
                     */
                    get selectableCategories() {
                        const needle = this.comboMemberSearch.trim().toLowerCase();
                        const list = this.system
                            .filter((c) => c.is_active)
                            .concat(this.mine.filter((c) => c.is_active))
                            .map((c) => ({ id: c.id, name: c.name, scope: c.scope }));

                        if (needle === '') return list;
                        return list.filter((c) => (c.name || '').toLowerCase().includes(needle));
                    },

                    memberLabel(combo) {
                        const names = (combo.items || []).map((i) => i.category_name).filter(Boolean);
                        if (names.length === 0) return '';
                        return names.join(' · ');
                    },

                    filterList(list) {
                        const needle = this.search.trim().toLowerCase();
                        if (needle === '') return list;
                        return list.filter((category) =>
                            (category.name || '').toLowerCase().includes(needle)
                            || (category.description || '').toLowerCase().includes(needle)
                        );
                    },

                    openCreate() {
                        this.form = { mode: 'create', id: null, name: '', description: '' };
                        this.$dispatch('open-modal', 'category-form');
                    },

                    openEdit(category) {
                        this.form = {
                            mode: 'edit',
                            id: category.id,
                            name: category.name,
                            description: category.description ?? '',
                        };
                        this.$dispatch('open-modal', 'category-form');
                    },

                    closeModal() {
                        this.$dispatch('close-modal', 'category-form');
                    },

                    async save() {
                        const name = (this.form.name || '').trim();
                        if (name === '') {
                            this.error = 'Vui lòng nhập tên danh mục.';
                            return;
                        }

                        const description = (this.form.description || '').trim();
                        const template = this.form.mode === 'create'
                            ? { url: this.apiBase(), method: 'POST' }
                            : { url: `${this.apiBase()}/${this.form.id}`, method: 'PATCH' };

                        const payload = await this.request(template.url, {
                            method: template.method,
                            body: JSON.stringify({
                                name,
                                description: description === '' ? null : description,
                            }),
                        });

                        if (!payload) return;

                        this.closeModal();
                        this.form = { mode: 'create', id: null, name: '', description: '' };
                        this.notice = template.method === 'POST'
                            ? 'Đã thêm danh mục chi tiêu.'
                            : 'Đã cập nhật danh mục.';
                        await this.load();
                    },

                    async remove(category) {
                        if (!window.confirm(`Xoá danh mục "${category.name}"?\nDanh mục đang được dùng sẽ chỉ bị ẩn đi.`)) {
                            return;
                        }

                        const payload = await this.request(`${this.apiBase()}/${category.id}`, { method: 'DELETE' });
                        if (!payload) return;

                        this.notice = payload.deleted === true
                            ? `Đã xoá danh mục "${category.name}".`
                            : `"${category.name}" đang được dùng cho giao dịch/rule cashback nên chỉ bị ẩn đi (dữ liệu cũ vẫn giữ nguyên).`;
                        await this.load();
                    },

                    // ===== Combo =====

                    openComboCreate() {
                        this.comboForm = { mode: 'create', id: null, name: '', description: '', category_ids: [] };
                        this.comboMemberSearch = '';
                        this.comboError = '';
                        this.$dispatch('open-modal', 'combo-form');
                    },

                    openComboEdit(combo) {
                        this.comboForm = {
                            mode: 'edit',
                            id: combo.id,
                            name: combo.name,
                            description: combo.description ?? '',
                            category_ids: [...(combo.category_ids ?? [])],
                        };
                        this.comboMemberSearch = '';
                        this.comboError = '';
                        this.$dispatch('open-modal', 'combo-form');
                    },

                    closeComboModal() {
                        this.$dispatch('close-modal', 'combo-form');
                    },

                    async saveCombo() {
                        const name = (this.comboForm.name || '').trim();
                        if (name === '') {
                            this.comboError = 'Vui lòng nhập tên combo.';
                            return;
                        }

                        if (this.comboForm.category_ids.length === 0) {
                            this.comboError = 'Combo phải có ít nhất một danh mục.';
                            return;
                        }

                        const description = (this.comboForm.description || '').trim();
                        const target = this.comboForm.mode === 'create'
                            ? { url: this.comboApiBase(), method: 'POST' }
                            : { url: `${this.comboApiBase()}/${this.comboForm.id}`, method: 'PATCH' };

                        // `comboError` tách riêng: lỗi combo không được xoá thông báo
                        // lỗi của modal danh mục (và ngược lại).
                        this.busy = true;
                        this.comboError = '';

                        let payload = null;
                        try {
                            const response = await fetch(target.url, {
                                method: target.method,
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                },
                                body: JSON.stringify({
                                    name,
                                    description: description === '' ? null : description,
                                    category_ids: this.comboForm.category_ids,
                                }),
                            });

                            payload = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                this.comboError = this.firstError(payload) || `Yêu cầu thất bại (${response.status}).`;
                                return;
                            }
                        } catch (e) {
                            this.comboError = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                            return;
                        } finally {
                            this.busy = false;
                        }

                        this.closeComboModal();
                        this.comboForm = { mode: 'create', id: null, name: '', description: '', category_ids: [] };
                        this.notice = target.method === 'POST'
                            ? 'Đã tạo combo danh mục.'
                            : 'Đã cập nhật combo danh mục.';
                        await this.load();
                    },
                };
            };

            window.Alpine.data('categoryManager', window.categoryManager);
        </script>
    @endpush
</x-credit-card.layout>