<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                {{ __('Tin tức khuyến mãi') }}
            </h2>
            <a href="{{ route('admin.promotion-news.create') }}" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
                Thêm tin thủ công
            </a>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-6xl mx-auto space-y-4">
        @if (session('success'))
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <form method="GET" action="{{ route('admin.promotion-news.index') }}" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Nguồn</label>
                <input type="text" name="source" list="promotion-news-sources" value="{{ $filters['source'] ?? '' }}" placeholder="coop, bhx..." class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
                <datalist id="promotion-news-sources">
                    @foreach ($knownSources as $source)
                        <option value="{{ $source }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Danh mục</label>
                <select name="category" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
                    <option value="">Tất cả</option>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Loại</label>
                <select name="source_type" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
                    <option value="">Tất cả</option>
                    <option value="auto" @selected(($filters['source_type'] ?? '') === 'auto')>Tự động</option>
                    <option value="manual" @selected(($filters['source_type'] ?? '') === 'manual')>Thủ công</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Trạng thái</label>
                <select name="is_active" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
                    <option value="">Tất cả</option>
                    <option value="1" @selected(($filters['is_active'] ?? '') === '1')>Đang bật</option>
                    <option value="0" @selected(($filters['is_active'] ?? '') === '0')>Đang tắt</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white text-sm font-medium rounded-lg transition-colors">
                    Lọc
                </button>
                <a href="{{ route('admin.promotion-news.index') }}" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition-colors">
                    Xoá lọc
                </a>
            </div>
        </form>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50">
                        <th class="px-4 py-3 text-left text-gray-500 font-medium text-xs uppercase">Ảnh</th>
                        <th class="px-4 py-3 text-left text-gray-500 font-medium text-xs uppercase">Tiêu đề</th>
                        <th class="px-4 py-3 text-left text-gray-500 font-medium text-xs uppercase">Nguồn</th>
                        <th class="px-4 py-3 text-left text-gray-500 font-medium text-xs uppercase">Danh mục</th>
                        <th class="px-4 py-3 text-center text-gray-500 font-medium text-xs uppercase">Loại</th>
                        <th class="px-4 py-3 text-center text-gray-500 font-medium text-xs uppercase">Trạng thái</th>
                        <th class="px-4 py-3 text-center text-gray-500 font-medium text-xs uppercase">Thứ tự</th>
                        <th class="px-4 py-3 text-center text-gray-500 font-medium text-xs uppercase">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($news as $item)
                        <tr class="border-b border-gray-50">
                            <td class="px-4 py-3">
                                @if ($item->image_url)
                                    <img src="{{ $item->image_url }}" alt="" class="h-10 w-16 object-contain bg-gray-50 rounded border border-gray-100">
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-gray-800 font-medium">{{ $item->title ?? '—' }}</div>
                                @if ($item->landing_url)
                                    <div class="text-gray-400 text-xs truncate max-w-xs">{{ $item->landing_url }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-700 text-xs">{{ $item->source }}</td>
                            <td class="px-4 py-3 text-gray-700 text-xs">{{ $categories[$item->category] ?? $item->category }}</td>
                            <td class="px-4 py-3 text-center">
                                @if ($item->source_type === 'auto')
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-sky-100 text-sky-800">Tự động</span>
                                @else
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-violet-100 text-violet-800">Thủ công</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if ($item->is_active)
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Đang bật</span>
                                @else
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">Đang tắt</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center text-gray-500 text-xs">{{ $item->sort_order }}</td>
                            <td class="px-4 py-3 text-center whitespace-nowrap">
                                @if ($item->source_type === 'manual')
                                    <a href="{{ route('admin.promotion-news.edit', $item) }}" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition-colors">Sửa</a>
                                    <form method="POST" action="{{ route('admin.promotion-news.toggle', $item) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 bg-amber-100 hover:bg-amber-200 text-amber-800 text-xs font-medium rounded-lg transition-colors">
                                            {{ $item->is_active ? 'Tắt' : 'Bật' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.promotion-news.destroy', $item) }}" class="inline" onsubmit="return confirm('Xoá tin khuyến mãi này?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1.5 bg-red-100 hover:bg-red-200 text-red-700 text-xs font-medium rounded-lg transition-colors">Xoá</button>
                                    </form>
                                @else
                                    <span class="text-gray-300 text-xs">Tự động</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-gray-300">Chưa có tin khuyến mãi nào</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $news->links() }}
        </div>
    </div>
</x-app-layout>
