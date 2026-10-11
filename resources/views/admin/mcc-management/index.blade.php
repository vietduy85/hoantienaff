<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                    🧩 Quản lý MCC
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">Hồ sơ MCC chính sách: MCC hoàn tiền, MCC loại trừ và tài liệu ngân hàng.</p>
            </div>
            @can('credit-cards.manage')
                <a href="{{ route('admin.credit-card.mcc.create', request('template_id') ? ['template_id' => request('template_id')] : []) }}"
                   class="shrink-0 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">+ Tạo hồ sơ MCC</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-6xl mx-auto space-y-4">
        @if (session('status'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        <form method="GET" action="{{ route('admin.credit-card.mcc.index') }}"
              class="grid gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:grid-cols-4">
            <div>
                <label class="block text-xs font-medium text-gray-500">Chính sách</label>
                <select name="template_id" class="mt-1 w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-400 text-sm">
                    <option value="">Tất cả</option>
                    @foreach ($templates as $template)
                        <option value="{{ $template['id'] }}" @selected(($filters['template_id'] ?? null) === $template['id'])>{{ $template['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-500">Tên hồ sơ / chính sách</label>
                <input type="text" name="name" value="{{ $filters['name'] ?? '' }}"
                       placeholder="Tìm theo tên..."
                       class="mt-1 w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-400 text-sm">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit"
                        class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors">Lọc</button>
                <a href="{{ route('admin.credit-card.mcc.index') }}"
                   class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-xl transition-colors">Xoá lọc</a>
            </div>
        </form>

        <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium">Hồ sơ MCC</th>
                        <th class="px-4 py-3 text-left font-medium">Chính sách</th>
                        <th class="px-4 py-3 text-left font-medium">MCC hoàn tiền</th>
                        <th class="px-4 py-3 text-left font-medium">MCC loại trừ</th>
                        <th class="px-4 py-3 text-left font-medium">Tài liệu</th>
                        <th class="px-4 py-3 text-left font-medium">Cập nhật</th>
                        <th class="px-4 py-3 text-right font-medium">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse ($profiles as $profile)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.credit-card.mcc.show', $profile['id']) }}"
                                   class="font-medium text-gray-800 hover:text-emerald-700">{{ $profile['name'] }}</a>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $profile['policy_name'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">{{ $profile['included_count'] }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700">{{ $profile['excluded_count'] }}</span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($profile['documents_count'] > 0)
                                    <span class="text-xs text-gray-600">{{ $profile['documents_count'] }} tệp</span>
                                @else
                                    <span class="text-xs text-gray-400">Chưa có</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ $profile['updated_at'] }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('admin.credit-card.mcc.show', $profile['id']) }}"
                                       class="inline-flex items-center px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition-colors">Xem</a>
                                    @can('credit-cards.manage')
                                        <a href="{{ route('admin.credit-card.mcc.edit', $profile['id']) }}"
                                           class="inline-flex items-center px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-xs font-medium rounded-lg transition-colors">Chỉnh sửa</a>
                                    @endcan
                                </td>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-gray-500">
                                Chưa có hồ sơ MCC nào.
                                @can('credit-cards.manage')
                                    <a href="{{ route('admin.credit-card.mcc.create') }}" class="font-medium text-emerald-600">Tạo hồ sơ MCC</a>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $profiles->links() }}</div>
    </div>
</x-app-layout>
