<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                    🎯 Chính sách hoàn tiền hệ thống
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">Quản lý các chính sách hoàn tiền dùng làm mẫu cho người dùng.</p>
            </div>
            <a href="{{ route('admin.credit-card-policies.create') }}"
               class="shrink-0 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
                + Tạo chính sách hệ thống
            </a>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-6xl mx-auto space-y-4">
        @if (session('success'))
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 text-sm text-rose-700">
                {{ session('error') }}
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            @forelse ($templates as $template)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-gray-800">{{ $template['name'] }}</h3>
                            @if ($template['description'])
                                <p class="text-sm text-gray-500 mt-0.5">{{ $template['description'] }}</p>
                            @endif
                        </div>
                        @if ($template['is_active'])
                            <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">🟢 Đang sử dụng</span>
                        @else
                            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600">⚪ Nháp</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-1.5 text-xs text-gray-600">
                        @if ($template['tiers_count'] !== null)
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2 py-0.5">{{ $template['tiers_count'] }} bậc</span>
                        @endif
                        @if ($template['categories_count'] !== null)
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2 py-0.5">{{ $template['categories_count'] }} danh mục</span>
                        @endif
                        @if ($template['version_no'] !== null)
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2 py-0.5">Version {{ $template['version_no'] }}</span>
                        @endif
                        @if ($template['default_version_no'] !== null)
                            <span class="rounded-lg bg-amber-50 border border-amber-100 px-2 py-0.5 text-amber-700">⭐ Mặc định: Version {{ $template['default_version_no'] }}</span>
                        @endif
                        @if ($template['effective_from'])
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2 py-0.5">Từ {{ $template['effective_from'] }}</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('admin.credit-card-policies.show', $template['id']) }}"
                           class="min-h-[44px] inline-flex items-center px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition-colors">Xem</a>
                        <a href="{{ route('admin.credit-card-policies.edit', $template['id']) }}"
                           class="min-h-[44px] inline-flex items-center px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-medium rounded-lg transition-colors">Chỉnh sửa</a>
                        <a href="{{ route('admin.credit-card-policies.show', $template['id']) }}#versions"
                           class="min-h-[44px] inline-flex items-center px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition-colors">Các phiên bản</a>
                        @if ($canManage)
                            <a href="{{ route('admin.credit-card.system-policies.clone', $template['id']) }}"
                               class="min-h-[44px] inline-flex items-center px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-xs font-medium rounded-lg transition-colors">📋 Clone</a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="sm:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
                    <p class="text-gray-500">Chưa có chính sách hệ thống nào.</p>
                    <a href="{{ route('admin.credit-card-policies.create') }}"
                       class="inline-block mt-3 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors">
                        + Tạo chính sách hệ thống
                    </a>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>