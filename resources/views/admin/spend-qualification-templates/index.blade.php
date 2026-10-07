{{--
    /admin/credit-card/spend-qualifications — "⛔ Quản lý mẫu điều kiện hoàn tiền đặc biệt".

    Mẫu là master data hệ thống: admin soạn bộ điều kiện chi tiêu; user chọn mẫu
    ĐANG BẬT khi tạo/chỉnh chính sách thẻ ⇒ hệ thống DEEP-CLONE điều kiện vào
    policy version của thẻ. Sửa mẫu KHÔNG ảnh hưởng bản clone đã phát cho thẻ.

    Mobile-first: danh sách dạng CARD, nút ≥44px, không bảng.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="min-w-0">
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                ⛔ Quản lý mẫu điều kiện hoàn tiền đặc biệt
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">Soạn bộ điều kiện chi tiêu dùng chung cho các chính sách hoàn tiền thẻ tín dụng.</p>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-3xl mx-auto space-y-4">
        <div x-data="ccSpendQualificationTemplateIndex({ templates: @js($templates) })" x-init="init()">

            <template x-if="notice">
                <div class="rounded-2xl bg-emerald-50 border border-emerald-200 p-4 text-sm text-emerald-800"
                     role="status" x-text="notice"></div>
            </template>
            <template x-if="pageError">
                <div class="rounded-2xl bg-rose-50 border border-rose-200 p-4 text-sm text-rose-700"
                     role="alert" x-text="pageError"></div>
            </template>

            {{-- Thanh công cụ --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 flex flex-col gap-3">
                <a href="{{ route('admin.credit-card.spend-qualifications.create') }}"
                   class="inline-flex items-center justify-center w-full sm:w-auto h-11 px-4 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium transition-colors shadow-sm">
                    ＋ Tạo mẫu mới
                </a>
                <p class="text-xs text-gray-500">
                    Mỗi kỳ sao kê, thẻ phải thoả TẤT CẢ điều kiện của mẫu (AND) mới được hoàn tiền.
                    Điều kiện đo trên chi tiêu thực tế của kỳ. User chỉ thấy mẫu đang bật.
                </p>
            </div>

            <template x-if="templates.length === 0">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-4 py-10 text-center">
                    <p class="text-sm text-gray-500">Chưa có mẫu điều kiện nào. Bấm "＋ Tạo mẫu mới" để bắt đầu.</p>
                </div>
            </template>

            <ul class="space-y-3">
                <template x-for="template in templates" :key="template.id">
                    <li class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-800" x-text="template.name"></p>
                                <code class="text-[11px] text-gray-400" x-text="template.slug"></code>
                            </div>
                            <span :class="template.is_active
                                             ? 'bg-emerald-100 text-emerald-700'
                                             : 'bg-gray-100 text-gray-500'"
                                  class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold"
                                  x-text="template.is_active ? 'ĐANG BẬT' : 'ĐÃ TẮT'"></span>
                        </div>

                        <p class="mt-2 text-xs text-gray-600 line-clamp-2" x-text="template.description || 'Không có mô tả.'"></p>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <template x-for="summary in conditionSummaries(template)" :key="summary">
                                <span class="inline-flex items-center px-2 py-1 rounded-lg bg-gray-50 border border-gray-100 text-[11px] text-gray-600"
                                      x-text="summary"></span>
                            </template>
                            <template x-if="!template.spend_qualification">
                                <span class="inline-flex items-center px-2 py-1 rounded-lg bg-rose-50 border border-rose-100 text-[11px] text-rose-600">Không có điều kiện</span>
                            </template>
                        </div>

                        <div class="mt-2 text-xs text-gray-400">
                            <span x-text="template.in_use ? `Đang dùng bởi ${template.usage_count} thẻ` : 'Chưa thẻ nào dùng'"></span>
                            <span class="mx-1">·</span>
                            <span x-text="`Thứ tự ${template.sort_order}`"></span>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <a :href="`{{ route('admin.credit-card.spend-qualifications.index') }}/${template.id}`"
                               class="inline-flex items-center justify-center h-11 sm:h-auto rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Xem
                            </a>
                            <a :href="`{{ route('admin.credit-card.spend-qualifications.index') }}/${template.id}/edit`"
                               class="inline-flex items-center justify-center h-11 sm:h-auto rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                                Chỉnh sửa
                            </a>
                            <button type="button"
                                    @click="toggleActive(template)"
                                    :disabled="busy"
                                    class="inline-flex items-center justify-center h-11 sm:h-auto rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                    x-text="template.is_active ? 'Tắt mẫu' : 'Bật mẫu'"></button>
                            <button type="button"
                                    @click="destroy(template)"
                                    :disabled="busy || template.in_use"
                                    x-show="!template.in_use"
                                    class="inline-flex items-center justify-center h-11 sm:h-auto rounded-xl border border-rose-200 bg-white px-4 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50 disabled:opacity-50">
                                Xoá
                            </button>
                        </div>
                    </li>
                </template>
            </ul>
        </div>
    </div>

    @push('scripts')
        <script>
            @include('credit-card.partials.money-js')

            window.ccSpendQualificationTemplateIndex = function (initial) {
                return {
                    templates: (initial && initial.templates) || [],
                    busy: false,
                    pageError: '',
                    notice: '',

                    init() {
                        const flash = window.localStorage.getItem('cc_sqf_flash');
                        if (flash) {
                            this.notice = flash;
                            window.localStorage.removeItem('cc_sqf_flash');
                        }
                    },

                    baseUrl() {
                        return '{{ route('admin.credit-card.spend-qualifications.index') }}';
                    },

                    conditionSummaries(template) {
                        const qualification = template.spend_qualification;
                        if (!qualification || !Array.isArray(qualification.conditions)) return [];

                        return qualification.conditions.map((condition) => {
                            if (condition.type === 'other') {
                                const excluded = (condition.excluded_category_ids || []).length;
                                return `Lĩnh vực khác ≥ ${ccMoneyVnd(condition.min_spend)}${excluded ? ` (trừ ${excluded} danh mục)` : ''}`;
                            }

                            const categoryName = condition.category_name || `#${condition.category_id}`;
                            return `${categoryName} ≥ ${ccMoneyVnd(condition.min_spend)}`;
                        });
                    },

                    async request(url, method, body = null) {
                        this.busy = true;
                        this.pageError = '';

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

                    async toggleActive(template) {
                        const payload = await this.request(
                            `{{ route('admin.credit-card.spend-qualifications.index') }}/api/spend-qualifications/${template.id}/active`,
                            'POST',
                            { is_active: !template.is_active },
                        );
                        if (!payload) return;
                        template.is_active = Boolean(payload.data.is_active);
                        this.notice = template.is_active ? `Đã bật mẫu "${template.name}".` : `Đã tắt mẫu "${template.name}".`;
                    },

                    async destroy(template) {
                        if (!window.confirm(`Xoá mẫu "${template.name}"? Không thể hoàn tác.`)) return;

                        const payload = await this.request(
                            `{{ route('admin.credit-card.spend-qualifications.index') }}/api/spend-qualifications/${template.id}`,
                            'DELETE',
                        );
                        if (!payload) return;

                        this.templates = this.templates.filter((item) => item.id !== template.id);
                        this.notice = `Đã xoá mẫu "${template.name}".`;
                    },
                };
            };

            document.addEventListener('alpine:init', () => {
                window.Alpine.data('ccSpendQualificationTemplateIndex', window.ccSpendQualificationTemplateIndex);
            });
        </script>
    @endpush
</x-app-layout>