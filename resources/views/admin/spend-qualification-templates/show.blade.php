{{--
    /admin/credit-card/spend-qualifications/{template} — chi tiết một mẫu điều kiện.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="min-w-0">
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                ⛔ <span x-text="template.name"></span>
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                <a href="{{ route('admin.credit-card.spend-qualifications.index') }}" class="hover:underline">← Quay lại danh sách</a>
            </p>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-3xl mx-auto">
        <div x-data="ccSpendQualificationTemplateShow({ template: @js($template) })" class="space-y-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5">
                <div class="flex items-center justify-between gap-3">
                    <code class="text-xs text-gray-400" x-text="'/' + template.slug"></code>
                    <a :href="`{{ route('admin.credit-card.spend-qualifications.index') }}/${template.id}/edit`"
                       class="inline-flex items-center justify-center h-11 sm:h-auto rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                        Chỉnh sửa
                    </a>
                </div>
                <p class="mt-2 text-sm text-gray-600" x-text="template.description || 'Không có mô tả.'"></p>

                <dl class="mt-4 grid sm:grid-cols-3 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-gray-400 uppercase">Trạng thái</dt>
                        <dd class="mt-0.5" x-text="template.is_active ? 'Đang bật' : 'Đã tắt'"></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400 uppercase">Thứ tự</dt>
                        <dd class="mt-0.5" x-text="template.sort_order"></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400 uppercase">Lượt dùng</dt>
                        <dd class="mt-0.5" x-text="template.in_use ? `${template.usage_count} thẻ` : 'Chưa thẻ nào'"></dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <header class="px-4 sm:px-5 py-3.5 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800 text-sm">ĐIỀU KIỆN CHI TIÊU</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Mọi điều kiện gộp bằng AND; đo trên chi tiêu thực tế của kỳ sao kê.</p>
                </header>

                <template x-if="!template.spend_qualification">
                    <div class="px-4 sm:px-5 py-10 text-center">
                        <p class="text-sm text-gray-500">Mẫu không có điều kiện.</p>
                    </div>
                </template>

                <ul class="divide-y divide-gray-100" x-show="template.spend_qualification">
                    <template x-for="(condition, index) in (template.spend_qualification.conditions || [])" :key="index">
                        <li class="px-4 sm:px-5 py-4 flex items-start gap-3">
                            <span class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-50 text-emerald-700 text-xs font-semibold"
                                  x-text="index + 1"></span>
                            <div class="min-w-0 flex-1 text-sm">
                                <template x-if="condition.type === 'category'">
                                    <p>
                                        Chi tiêu của <b x-text="condition.category_name || `#${condition.category_id}`"></b>
                                        trong kỳ đạt <b x-text="summary(condition)"></b>
                                    </p>
                                </template>
                                <template x-if="condition.type === 'other'">
                                    <p>
                                        Tổng chi tiêu cả kỳ trừ các danh mục đã loại đạt <b x-text="summary(condition)"></b>
                                    </p>
                                </template>
                                <template x-if="condition.type === 'other' && condition.excluded_category_ids.length">
                                    <p class="mt-1 text-xs text-gray-500">
                                        Loại trừ: <span x-for="id in condition.excluded_category_ids" x-text="`#${id} `"></span>
                                    </p>
                                </template>
                                <template x-if="condition.note">
                                    <p class="mt-1 text-xs text-gray-400 italic" x-text="condition.note"></p>
                                </template>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            @include('credit-card.partials.money-js')

            window.ccSpendQualificationTemplateShow = function (initial) {
                return {
                    template: (initial && initial.template) || {},

                    summary(condition) {
                        return ccMoneyVnd(condition.min_spend);
                    },
                };
            };

            document.addEventListener('alpine:init', () => {
                window.Alpine.data('ccSpendQualificationTemplateShow', window.ccSpendQualificationTemplateShow);
            });
        </script>
    @endpush
</x-app-layout>