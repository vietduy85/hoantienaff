{{--
    /admin/credit-card/spend-qualifications/create | /{template}/edit

    Soạn / chỉnh MẪU "Điều kiện hoàn tiền đặc biệt" (master data hệ thống).
    User chọn một mẫu ĐANG BẬT khi tạo/chỉnh chính sách thẻ; hệ thống DEEP-CLONE
    toàn bộ điều kiện của mẫu vào policy version của thẻ (sửa mẫu không ảnh
    hưởng bản clone — `SpendQualificationService::copyFromTemplate`).

    Quy tắc điều kiện (server vẫn kiểm lại — trang này chỉ soạn payload):
      - DANH MỤC: tổng chi tiêu THỰC TẾ của đúng danh mục đó trong kỳ >= min.
      - LĨNH VỰC KHÁC: tổng chi tiêu thực tế cả kỳ TRỪ các danh mục loại trừ >= min.
        Mỗi mẫu tối đa MỘT "Lĩnh vực khác" và nó LUÔN được đẩy xuống cuối.

    Mobile-first: dùng Alpine, không bảng, nút ≥44px.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="min-w-0">
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                ⛔ {{ $template === null ? 'Tạo mẫu điều kiện hoàn tiền đặc biệt' : 'Chỉnh sửa mẫu điều kiện hoàn tiền đặc biệt' }}
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                <a href="{{ route('admin.credit-card.spend-qualifications.index') }}" class="hover:underline">← Quay lại danh sách</a>
            </p>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-3xl mx-auto">
        <div x-data="ccSpendQualificationTemplateEditor({
                 template: @js($template),
                 qualification: @js($qualification),
                 systemCategories: @js($systemCategories),
                 endpoint: @js($template === null
                     ? route('admin.credit-card.spend-qualifications.api.store')
                     : route('admin.credit-card.spend-qualifications.api.update', ['template' => $template['id']])),
                 method: @js($template === null ? 'POST' : 'PATCH'),
                 backUrl: @js(route('admin.credit-card.spend-qualifications.index')),
             })"
             x-init="init()"
             class="space-y-4 sm:space-y-5">

            <template x-if="pageError">
                <div class="rounded-2xl bg-rose-50 border border-rose-200 p-4 text-sm text-rose-700"
                     role="alert" x-text="pageError"></div>
            </template>

            {{-- Thông tin chung --}}
            <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-4">
                <h3 class="font-semibold text-gray-800 text-sm">THÔNG TIN CHUNG</h3>

                <div>
                    <label for="sq-name" class="block text-sm font-medium text-gray-700">
                        Tên mẫu <span class="text-rose-500">*</span>
                    </label>
                    <input id="sq-name"
                           type="text"
                           x-model="name"
                           @input="onNameInput()"
                           maxlength="150"
                           placeholder="Ví dụ: Mua sắm từ 3 triệu/kỳ"
                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <p class="mt-1 text-xs text-rose-600" x-show="errors.name" x-text="errors.name" role="alert"></p>
                </div>

                <div>
                    <label for="sq-slug" class="block text-sm font-medium text-gray-700">
                        Slug <span class="text-rose-500">*</span>
                    </label>
                    <input id="sq-slug"
                           type="text"
                           x-model="slug"
                           @focus="slugTouched = true"
                           maxlength="150"
                           placeholder="mua-sam-tu-3-trieu-ky"
                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <p class="mt-1 text-xs text-gray-500">Chữ thường, số, gạch ngang; phải duy nhất. Tự tạo từ tên, giữ nguyên nếu không tự đổi.</p>
                    <p class="mt-1 text-xs text-rose-600" x-show="errors.slug" x-text="errors.slug" role="alert"></p>
                </div>

                <div>
                    <label for="sq-desc" class="block text-sm font-medium text-gray-700">Mô tả</label>
                    <textarea id="sq-desc" x-model="description" rows="2" maxlength="1000"
                              placeholder="Mô tả ngắn hiển thị cho người dùng khi chọn mẫu"
                              class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                </div>

                <div>
                    <label for="sq-note" class="block text-sm font-medium text-gray-700">Ghi chú nội bộ</label>
                    <textarea id="sq-note" x-model="note" rows="2" maxlength="5000"
                              placeholder="Ghi chú cho admin (không hiển thị cho người dùng)"
                              class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label for="sq-sort" class="block text-sm font-medium text-gray-700">Thứ tự</label>
                        <input id="sq-sort" type="number" x-model.number="sortOrder" min="0"
                               class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <label class="mt-1 flex items-start gap-3 sm:items-center cursor-pointer select-none">
                        <input type="checkbox" x-model="isActive"
                               class="mt-0.5 sm:mt-0 h-5 w-5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm text-gray-700">
                            Đang bật
                            <span class="block text-xs text-gray-400">Mẫu đang bật mới hiện cho người dùng chọn.</span>
                        </span>
                    </label>
                </div>
            </section>

            {{-- Bộ điều kiện chi tiêu --}}
            <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <header class="px-4 sm:px-5 py-3.5 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800 text-sm">
                        ĐIỀU KIỆN CHI TIÊU
                        <span class="ml-1 font-medium text-gray-400" x-text="`(${conditions.length})`"></span>
                    </h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Mọi điều kiện gộp bằng <b>AND</b>: để được hoàn tiền, thẻ phải thoả TẤT CẢ các điều kiện trong kỳ sao kê (đo trên chi tiêu thực tế, không phải tiền hoàn).
                    </p>
                </header>

                <template x-if="conditions.length === 0">
                    <div class="px-4 sm:px-5 py-8 text-center">
                        <p class="text-sm text-gray-500">Chưa có điều kiện nào. Bấm nút bên dưới để thêm.</p>
                    </div>
                </template>

                <ul class="divide-y divide-gray-100">
                    <template x-for="(condition, index) in conditions" :key="index">
                        <li class="px-4 sm:px-5 py-4 space-y-3">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-50 text-emerald-700 text-xs font-semibold"
                                          x-text="index + 1"></span>
                                    <span class="text-xs text-gray-400" x-text="condition.type === 'other' ? 'Lĩnh vực khác' : 'Danh mục cụ thể'"></span>
                                </div>
                                <button type="button"
                                        @click="removeCondition(index)"
                                        class="shrink-0 inline-flex items-center justify-center w-11 h-11 rounded-lg text-gray-400 hover:bg-rose-50 hover:text-rose-600"
                                        aria-label="Xoá điều kiện">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>

                            <template x-if="condition.type === 'category'">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Danh mục</label>
                                    <select x-model="condition.category_id" required
                                            class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                        <option value="">— Chọn danh mục —</option>
                                        <template x-for="category in availableCategories(condition.category_id)" :key="category.id">
                                            <option :value="category.id" x-text="category.name"></option>
                                        </template>
                                    </select>
                                    <p class="mt-1 text-xs text-rose-600" x-show="errors[`conditions.${index}.category_id`]" x-text="errors[`conditions.${index}.category_id`]" role="alert"></p>
                                </div>
                            </template>

                            <template x-if="condition.type === 'other'">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">
                                        Loại trừ các danh mục <span class="font-normal text-gray-400">(bỏ trống = tính trên tổng chi tiêu cả kỳ)</span>
                                    </label>
                                    <div class="mt-1 flex flex-wrap gap-1.5">
                                        <template x-for="category in systemCategories" :key="category.id">
                                            <button type="button"
                                                    @click="toggleExcluded(condition, category.id)"
                                                    :class="condition.excluded_category_ids.includes(category.id)
                                                                ? 'bg-emerald-600 text-white border-emerald-600'
                                                                : 'bg-white text-gray-600 border-gray-200 hover:border-emerald-300'"
                                                    class="inline-flex items-center px-3 h-9 rounded-full border text-xs font-medium transition-colors"
                                                    x-text="category.name"></button>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    Chi tiêu tối thiểu trong kỳ <span class="text-rose-500">*</span>
                                </label>
                                <div class="mt-1 relative">
                                    <input type="number"
                                           x-model.number="condition.min_spend"
                                           min="0"
                                           step="1000"
                                           placeholder="Ví dụ: 3000000"
                                           class="w-full rounded-xl border-gray-300 text-sm shadow-sm pr-10 focus:border-emerald-500 focus:ring-emerald-500">
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-400">đ</span>
                                </div>
                                <p class="mt-1 text-xs text-rose-600" x-show="errors[`conditions.${index}.min_spend`]" x-text="errors[`conditions.${index}.min_spend`]" role="alert"></p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Ghi chú điều kiện</label>
                                <input type="text" x-model="condition.note" maxlength="5000"
                                       placeholder="Không bắt buộc"
                                       class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                        </li>
                    </template>
                </ul>

                <div class="px-4 sm:px-5 py-4 border-t border-gray-100 flex flex-col sm:flex-row gap-2">
                    <button type="button"
                            @click="addCategory()"
                            class="inline-flex items-center justify-center h-11 px-4 rounded-xl border border-dashed border-emerald-300 text-emerald-700 text-sm font-medium hover:bg-emerald-50">
                        ＋ Thêm điều kiện danh mục
                    </button>
                    <button type="button"
                            @click="addOther()"
                            :disabled="hasOther"
                            class="inline-flex items-center justify-center h-11 px-4 rounded-xl border border-dashed border-emerald-300 text-emerald-700 text-sm font-medium hover:bg-emerald-50 disabled:opacity-40 disabled:cursor-not-allowed">
                        ＋ Thêm "Lĩnh vực khác"
                    </button>
                </div>
            </section>

            <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2 pt-1">
                <a :href="backUrl"
                   class="inline-flex items-center justify-center h-11 sm:h-auto rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Huỷ
                </a>
                <button type="button"
                        @click="save()"
                        :disabled="busy"
                        class="inline-flex items-center justify-center h-11 sm:h-auto rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                    <span x-text="busy ? 'Đang lưu…' : 'Lưu mẫu'"></span>
                </button>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            window.ccSpendQualificationTemplateEditor = function (initial) {
                return {
                    template: (initial && initial.template) || null,
                    qualification: (initial && initial.qualification) || null,
                    systemCategories: (initial && initial.systemCategories) || [],
                    endpoint: (initial && initial.endpoint) || '',
                    method: (initial && initial.method) || 'POST',
                    backUrl: (initial && initial.backUrl) || '',

                    name: '',
                    slug: '',
                    description: '',
                    note: '',
                    isActive: true,
                    sortOrder: 0,
                    conditions: [],
                    slugTouched: false,
                    busy: false,
                    pageError: '',
                    errors: {},

                    init() {
                        if (this.qualification && Array.isArray(this.qualification.conditions)) {
                            this.conditions = this.qualification.conditions.map((condition) => ({
                                type: condition.type,
                                category_id: condition.category_id ?? null,
                                min_spend: condition.min_spend ?? '',
                                note: condition.note ?? '',
                                excluded_category_ids: Array.isArray(condition.excluded_category_ids)
                                    ? condition.excluded_category_ids.map(Number)
                                    : [],
                            }));
                        }

                        if (this.template) {
                            this.name = this.template.name || '';
                            this.slug = this.template.slug || '';
                            this.description = this.template.description || '';
                            this.note = this.template.note || '';
                            this.isActive = Boolean(this.template.is_active);
                            this.sortOrder = this.template.sort_order ?? 0;
                        }
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
                            this.slug = this.slugify(this.name);
                        }
                    },

                    get hasOther() {
                        return this.conditions.some((condition) => condition.type === 'other');
                    },

                    categoryIdsInUse() {
                        return this.conditions
                            .filter((condition) => condition.type === 'category' && condition.category_id)
                            .map((condition) => Number(condition.category_id));
                    },

                    availableCategories(currentId) {
                        const used = this.categoryIdsInUse().filter((id) => id !== Number(currentId));
                        return this.systemCategories.filter((category) => !used.includes(Number(category.id)));
                    },

                    addCategory() {
                        const used = this.categoryIdsInUse();
                        const firstFree = this.systemCategories.find((category) => !used.includes(Number(category.id)));

                        if (!firstFree) {
                            this.pageError = 'Đã dùng hết danh mục hệ thống để làm điều kiện.';
                            return;
                        }

                        this.pageError = '';
                        this.conditions.push({
                            type: 'category',
                            category_id: firstFree.id,
                            min_spend: '',
                            note: '',
                            excluded_category_ids: [],
                        });
                    },

                    addOther() {
                        if (this.hasOther) return;
                        this.pageError = '';
                        this.conditions.push({
                            type: 'other',
                            category_id: null,
                            min_spend: '',
                            note: '',
                            excluded_category_ids: [],
                        });
                    },

                    removeCondition(index) {
                        this.conditions.splice(index, 1);
                    },

                    toggleExcluded(condition, categoryId) {
                        categoryId = Number(categoryId);
                        const index = condition.excluded_category_ids.indexOf(categoryId);
                        if (index === -1) {
                            condition.excluded_category_ids.push(categoryId);
                        } else {
                            condition.excluded_category_ids.splice(index, 1);
                        }
                    },

                    parsenumber(value) {
                        return parseFloat(String(value ?? '').replace(/[^0-9.]/g, ''));
                    },

                    validate() {
                        this.errors = {};

                        if ((this.name || '').trim() === '') {
                            this.errors.name = 'Vui lòng nhập tên mẫu.';
                        }
                        if ((this.slug || '').trim() === '') {
                            this.errors.slug = 'Vui lòng nhập slug mẫu.';
                        }
                        if (this.conditions.length === 0) {
                            this.pageError = 'Vui lòng thêm ít nhất một điều kiện chi tiêu.';
                            return false;
                        }

                        const usedCategoryIds = [];
                        let valid = true;

                        this.conditions.forEach((condition, index) => {
                            if (condition.type === 'category') {
                                if (!condition.category_id) {
                                    this.errors[`conditions.${index}.category_id`] = 'Vui lòng chọn danh mục.';
                                    valid = false;
                                } else if (usedCategoryIds.includes(Number(condition.category_id))) {
                                    this.errors[`conditions.${index}.category_id`] = 'Danh mục này đã có trong điều kiện khác.';
                                    valid = false;
                                } else {
                                    usedCategoryIds.push(Number(condition.category_id));
                                }
                            }

                            const min = this.parsenumber(condition.min_spend);
                            if (condition.min_spend === '' || condition.min_spend === null || isNaN(min) || min < 0) {
                                this.errors[`conditions.${index}.min_spend`] = 'Vui lòng nhập số tiền tối thiểu (>= 0).';
                                valid = false;
                            } else {
                                condition.min_spend = min;
                            }
                        });

                        return valid;
                    },

                    async save() {
                        this.pageError = '';
                        this.busy = true;

                        try {
                            if (!this.validate()) {
                                return;
                            }

                            const payload = {
                                name: this.name.trim(),
                                slug: this.slug.trim(),
                                description: (this.description || '').trim() === '' ? null : this.description.trim(),
                                note: (this.note || '').trim() === '' ? null : this.note.trim(),
                                is_active: this.isActive,
                                sort_order: Number(this.sortOrder || 0),
                                spend_qualification: {
                                    conditions: this.conditions.map((condition) => ({
                                        type: condition.type,
                                        category_id: condition.type === 'category' ? Number(condition.category_id) : null,
                                        min_spend: this.parsenumber(condition.min_spend),
                                        note: (condition.note || '').trim() === '' ? null : condition.note.trim(),
                                        excluded_category_ids: condition.type === 'other'
                                            ? condition.excluded_category_ids.map(Number)
                                            : [],
                                    })),
                                },
                            };

                            const response = await fetch(this.endpoint, {
                                method: this.method,
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                },
                                body: JSON.stringify(payload),
                            });

                            const data = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                if (data.errors) {
                                    for (const [field, messages] of Object.entries(data.errors)) {
                                        this.errors[field] = Array.isArray(messages) ? messages[0] : messages;
                                    }
                                }
                                this.pageError = data.message || `Yêu cầu thất bại (${response.status}).`;
                                return;
                            }

                            window.localStorage.setItem('cc_sqf_flash', 'Đã lưu mẫu điều kiện hoàn tiền đặc biệt.');
                            window.location.href = this.backUrl;
                        } catch (e) {
                            this.pageError = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                        } finally {
                            this.busy = false;
                        }
                    },
                };
            };

            document.addEventListener('alpine:init', () => {
                window.Alpine.data('ccSpendQualificationTemplateEditor', window.ccSpendQualificationTemplateEditor);
            });
        </script>
    @endpush
</x-app-layout>