{{--
    /admin/credit-card/system-categories — "⚙️ Quản lý danh mục hệ thống".

    Module ADMIN quản lý danh mục HỆ THỐNG (master data): CHỈ 3 thao tác
      - thêm mới (modal)
      - đổi tên (modal)
      - sắp xếp lại (↑/↓ + kéo-thả)
    KHÔNG có xoá / ẩn / khôi phục.

    Bất biến: `category_id` là định danh, `name` là thuộc tính hiện tại. Đổi tên
    chỉ chạm đúng bản ghi Category (cùng id) ⇒ mọi policy rule / giao dịch /
    template giữ nguyên tham chiếu và tự hiển thị tên mới (không snapshot).

    Mobile-first (390/375/360): danh sách dạng CARD, nút bấm ≥44px, không bảng.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="min-w-0">
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                ⚙️ Quản lý danh mục hệ thống
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">Quản lý các danh mục chi tiêu dùng chung cho chính sách hoàn tiền thẻ tín dụng.</p>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-2xl mx-auto space-y-4">
        <div x-data="systemCategoryAdmin({ categories: @js($categories) })"
             x-init="init()"
             class="space-y-4 sm:space-y-5">

            {{-- Thanh công cụ: tìm kiếm + thêm mới --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 flex flex-col gap-3">
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400" aria-hidden="true">🔎</span>
                    <input id="syscat-search"
                           type="search"
                           x-model="search"
                           placeholder="Tìm danh mục theo tên hoặc slug…"
                           class="w-full rounded-xl border-gray-300 pl-10 pr-4 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                </div>
                <button type="button"
                        @click="openCreate()"
                        class="inline-flex items-center justify-center w-full sm:w-auto h-11 px-4 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium transition-colors shadow-sm">
                    + Thêm danh mục
                </button>
                <p class="text-xs text-gray-500">
                    Danh mục hệ thống dùng chung cho mọi chính sách hoàn tiền. Chỉ quản trị viên mới có thể thêm, đổi tên và sắp xếp. Không có thao tác xoá.
                </p>
            </div>

            {{-- Thông báo --}}
            <template x-if="notice">
                <div class="rounded-2xl bg-emerald-50 border border-emerald-200 p-4 text-sm text-emerald-800"
                     role="status" x-text="notice"></div>
            </template>

            <template x-if="pageError">
                <div class="rounded-2xl bg-rose-50 border border-rose-200 p-4 text-sm text-rose-700"
                     role="alert" x-text="pageError"></div>
            </template>

            {{-- Danh sách danh mục hệ thống --}}
            <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <header class="flex items-center justify-between gap-3 px-4 sm:px-5 py-3.5 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800 text-sm">
                        🏦 <span class="ml-1">DANH MỤC HỆ THỐNG</span>
                        <span x-text="`(${filtered.length})`" class="ml-1 font-medium text-gray-400"></span>
                    </h3>
                    <span x-show="search.trim() !== ''" class="text-xs text-gray-400">Xoá từ khoá để sắp xếp lại</span>
                </header>

                <template x-if="filtered.length === 0">
                    <div class="px-4 sm:px-5 py-10 text-center">
                        <p class="mt-1 text-sm text-gray-500" x-text="categories.length === 0
                            ? 'Chưa có danh mục hệ thống nào.'
                            : 'Không tìm thấy danh mục nào khớp từ khoá tìm kiếm.'"></p>
                    </div>
                </template>

                <ul class="divide-y divide-gray-100">
                    <template x-for="category in filtered" :key="category.id">
                        <li :class="draggingOverId === category.id && draggingId !== category.id
                                       ? 'ring-2 ring-inset ring-emerald-400 bg-emerald-50/50'
                                       : ''"
                            :draggable="canReorder"
                            @dragstart="onDragStart($event, category.id)"
                            @dragover.prevent="draggingOverId = category.id"
                            @drop.prevent.stop="onDrop($event, category.id)"
                            @dragend="onDragEnd()"
                            class="flex items-center gap-3 px-4 sm:px-5 py-3">
                            {{-- Drag handle --}}
                            <span x-show="canReorder"
                                  :class="draggingId === category.id ? 'cursor-grabbing text-emerald-600' : 'cursor-grab text-gray-300'"
                                  class="hidden sm:inline-flex shrink-0 text-lg leading-none select-none"
                                  aria-hidden="true">⋮⋮</span>
                            {{-- STT --}}
                            <span class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full bg-gray-100 text-[11px] font-semibold text-gray-600"
                                  x-text="stt(category.id)"></span>
                            {{-- Icon --}}
                            <span class="text-lg leading-none shrink-0" aria-hidden="true" x-text="category.icon"></span>
                            {{-- Tên + slug --}}
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-800" x-text="category.name"></p>
                                <code class="text-[11px] text-gray-400" x-text="category.slug"></code>
                            </div>
                            {{-- Thao tác: ↑ ↓ + sửa (≥44px touch) --}}
                            <div class="shrink-0 flex items-center gap-0.5">
                                <button type="button"
                                        @click="moveUp(category.id)"
                                        :disabled="!canReorder"
                                        title="Di chuyển lên"
                                        aria-label="Di chuyển lên"
                                        class="inline-flex items-center justify-center w-11 h-11 rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-800 disabled:opacity-30 disabled:cursor-not-allowed">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                    </svg>
                                </button>
                                <button type="button"
                                        @click="moveDown(category.id)"
                                        :disabled="!canReorder"
                                        title="Di chuyển xuống"
                                        aria-label="Di chuyển xuống"
                                        class="inline-flex items-center justify-center w-11 h-11 rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-800 disabled:opacity-30 disabled:cursor-not-allowed">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <button type="button"
                                        @click="openEdit(category)"
                                        :disabled="busy"
                                        title="Sửa tên danh mục"
                                        aria-label="Sửa tên danh mục"
                                        class="inline-flex items-center justify-center w-11 h-11 rounded-lg p-2 text-gray-500 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-50">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                            </div>
                        </li>
                    </template>
                </ul>
            </section>

            {{-- Modal thêm / sửa danh mục --}}
            <x-modal name="system-category-form" :show="false" maxWidth="md">
                <div class="p-5 sm:p-6">
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h3 class="font-bold text-gray-800"
                            x-text="form.mode === 'create' ? 'Thêm danh mục hệ thống' : 'Sửa tên danh mục hệ thống'"></h3>
                        <button type="button" @click="closeModal()"
                                class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" aria-label="Đóng">
                            ✕
                        </button>
                    </div>

                    <template x-if="pageError">
                        <div class="mb-3 rounded-xl bg-rose-50 border border-rose-200 p-3 text-sm text-rose-700"
                             role="alert" x-text="pageError"></div>
                    </template>

                    <form @submit.prevent="save()" class="space-y-4">
                        <div>
                            <label for="syscat-name" class="block text-sm font-medium text-gray-700">
                                Tên danh mục <span class="text-rose-500">*</span>
                            </label>
                            <input id="syscat-name"
                                   type="text"
                                   x-model="form.name"
                                   @input="onNameInput()"
                                   maxlength="150"
                                   placeholder="Ví dụ: Y tế"
                                   class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <p class="mt-1 text-xs text-rose-600" x-show="form.errors.name" x-text="form.errors.name" role="alert"></p>
                        </div>

                        <div>
                            <label for="syscat-slug" class="block text-sm font-medium text-gray-700">
                                Slug <span class="text-rose-500">*</span>
                            </label>
                            <input id="syscat-slug"
                                   type="text"
                                   x-model="form.slug"
                                   @focus="markSlugTouched()"
                                   maxlength="150"
                                   placeholder="y-te"
                                   class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <p class="mt-1 text-xs text-gray-500">Chữ thường, số và gạch ngang (ví dụ <code class="text-[11px]">y-te</code>), phải duy nhất trong danh mục hệ thống. Khi thêm mới, slug được tự tạo từ tên; khi sửa, slug giữ nguyên nếu bạn không tự đổi. Ký tự lạ sẽ được hệ thống tự đổi thành gạch nối.</p>
                            <p class="mt-1 text-xs text-rose-600" x-show="form.errors.slug" x-text="form.errors.slug" role="alert"></p>
                        </div>

                        <div>
                            <label for="syscat-desc" class="block text-sm font-medium text-gray-700">Mô tả</label>
                            <textarea id="syscat-desc"
                                      x-model="form.description"
                                      rows="3"
                                      maxlength="1000"
                                      placeholder="Mô tả ngắn về nhóm chi tiêu này (không bắt buộc)"
                                      class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                            <p class="mt-1 text-xs text-rose-600" x-show="form.errors.description" x-text="form.errors.description" role="alert"></p>
                        </div>

                        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2 pt-1">
                            <button type="button"
                                    @click="closeModal()"
                                    class="inline-flex items-center justify-center h-11 sm:h-auto rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Huỷ
                            </button>
                            <button type="submit"
                                    :disabled="busy"
                                    class="inline-flex items-center justify-center h-11 sm:h-auto rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                                <span x-text="form.mode === 'create' ? 'Lưu danh mục' : 'Cập nhật tên'"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </x-modal>
        </div>
    </div>

    @push('scripts')
        <script>
            window.systemCategoryAdmin = function (initial) {
                return {
                    categories: (initial && initial.categories) || [],
                    search: '',
                    busy: false,
                    pageError: '',
                    notice: '',

                    form: { mode: 'create', id: null, name: '', slug: '', description: '', errors: {} },
                    slugTouched: false,
                    draggingId: null,
                    draggingOverId: null,

                    init() {
                        const flash = window.localStorage.getItem('cc_syscat_flash');
                        if (flash) {
                            this.notice = flash;
                            window.localStorage.removeItem('cc_syscat_flash');
                        }
                    },

                    baseUrl() {
                        return '{{ route('admin.credit-card.system-categories.index') }}';
                    },

                    reorderUrl() {
                        return '{{ route('admin.credit-card.system-categories.reorder') }}';
                    },

                    get filtered() {
                        const needle = this.search.trim().toLowerCase();
                        if (needle === '') return this.categories;
                        return this.categories.filter((category) =>
                            (category.name || '').toLowerCase().includes(needle)
                            || (category.slug || '').toLowerCase().includes(needle)
                        );
                    },

                    get canReorder() {
                        return this.search.trim() === '';
                    },

                    stt(id) {
                        const index = this.categories.findIndex((category) => category.id === id);
                        return index === -1 ? '' : index + 1;
                    },

                    slugify(value) {
                        return (value || '').toLowerCase()
                            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                            .replace(/đ/g, 'd')
                            .replace(/[^a-z0-9]+/g, '-')
                            .replace(/^-+|-+$/g, '')
                            .replace(/(-)+/g, '-');
                    },

                    onNameInput() {
                        if (!this.slugTouched) {
                            this.form.slug = this.slugify(this.form.name);
                        }
                    },

                    markSlugTouched() {
                        this.slugTouched = true;
                    },

                    openCreate() {
                        this.form = { mode: 'create', id: null, name: '', slug: '', description: '', errors: {} };
                        this.slugTouched = false;
                        this.pageError = '';
                        this.$dispatch('open-modal', 'system-category-form');
                    },

                    openEdit(category) {
                        this.form = {
                            mode: 'edit',
                            id: category.id,
                            name: category.name,
                            slug: category.slug,
                            description: category.description ?? '',
                            errors: {},
                        };
                        this.slugTouched = true;
                        this.pageError = '';
                        this.$dispatch('open-modal', 'system-category-form');
                    },

                    closeModal() {
                        this.$dispatch('close-modal', 'system-category-form');
                    },

                    async request(url, method, body = null) {
                        this.busy = true;
                        this.pageError = '';
                        this.form.errors = {};

                        try {
                            const response = await fetch(url, {
                                method,
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                },
                                body: body ? JSON.stringify(body) : null,
                            });

                            const payload = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                if (payload.errors) {
                                    for (const [field, messages] of Object.entries(payload.errors)) {
                                        this.form.errors[field] = Array.isArray(messages) ? messages[0] : messages;
                                    }
                                }
                                this.pageError = payload.message || `Yêu cầu thất bại (${response.status}).`;
                                return null;
                            }

                            return payload;
                        } catch (e) {
                            this.pageError = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                            return null;
                        } finally {
                            this.busy = false;
                        }
                    },

                    async save() {
                        const name = (this.form.name || '').trim();
                        const slug = (this.form.slug || '').trim();

                        if (name === '') {
                            this.form.errors.name = 'Vui lòng nhập tên danh mục.';
                            return;
                        }
                        if (slug === '') {
                            this.form.errors.slug = 'Vui lòng nhập slug danh mục.';
                            return;
                        }

                        const description = (this.form.description || '').trim() === ''
                            ? null
                            : this.form.description.trim();

                        const template = this.form.mode === 'create'
                            ? { url: this.baseUrl(), method: 'POST' }
                            : { url: `${this.baseUrl()}/${this.form.id}`, method: 'PATCH' };

                        const method = this.form.mode === 'create' ? 'POST' : 'PATCH';
                        const payload = await this.request(template.url, method, { name, slug, description });

                        if (!payload) return;

                        const savedName = (payload.data && payload.data.name) || name;
                        const flash = template.method === 'POST'
                            ? `Đã thêm danh mục hệ thống "${savedName}".`
                            : `Đã đổi tên danh mục thành "${savedName}".`;

                        this.closeModal();
                        window.localStorage.setItem('cc_syscat_flash', flash);
                        window.location.reload();
                    },

                    moveUp(id) {
                        const index = this.categories.findIndex((category) => category.id === id);
                        if (index <= 0) return;
                        const [moved] = this.categories.splice(index, 1);
                        this.categories.splice(index - 1, 0, moved);
                        this.persistOrder();
                    },

                    moveDown(id) {
                        const index = this.categories.findIndex((category) => category.id === id);
                        if (index === -1 || index >= this.categories.length - 1) return;
                        const [moved] = this.categories.splice(index, 1);
                        this.categories.splice(index + 1, 0, moved);
                        this.persistOrder();
                    },

                    async persistOrder() {
                        const payload = await this.request(this.reorderUrl(), 'POST', {
                            ordered_ids: this.categories.map((category) => category.id),
                        });

                        if (!payload) {
                            this.pageError = 'Không thể lưu thứ tự mới. Hãy thử lại.';
                            return;
                        }

                        this.categories.forEach((category, index) => {
                            category.sort_order = index + 1;
                        });
                        this.notice = 'Đã lưu thứ tự sắp xếp mới.';
                    },

                    onDragStart(event, id) {
                        if (!this.canReorder) {
                            event.preventDefault();
                            return;
                        }
                        this.draggingId = id;
                        event.dataTransfer.effectAllowed = 'move';
                    },

                    onDrop(event, id) {
                        const from = this.draggingId;
                        this.draggingId = null;
                        this.draggingOverId = null;

                        if (from === null || from === undefined || from === id) return;

                        const fromIndex = this.categories.findIndex((category) => category.id === from);
                        const toIndex = this.categories.findIndex((category) => category.id === id);
                        if (fromIndex === -1 || toIndex === -1) return;

                        const [moved] = this.categories.splice(fromIndex, 1);
                        this.categories.splice(toIndex, 0, moved);
                        this.persistOrder();
                    },

                    onDragEnd() {
                        this.draggingId = null;
                        this.draggingOverId = null;
                    },
                };
            };

            document.addEventListener('alpine:init', () => {
                window.Alpine.data('systemCategoryAdmin', window.systemCategoryAdmin);
            });
        </script>
    @endpush
</x-app-layout>