@php
    $value = fn (string $field, $default = null) => old($field, $news->{$field} ?? $default);
    $dateValue = function (?string $field) use ($news) {
        $date = $news->{$field} ?? null;

        return $date ? \Illuminate\Support\Carbon::parse($date)->format('Y-m-d\TH:i') : '';
    };
@endphp

@if ($errors->any())
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700 mb-4">
        <ul class="list-disc list-inside space-y-0.5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nguồn <span class="text-red-500">*</span></label>
        <input type="text" name="source" list="promotion-news-sources" value="{{ $value('source') }}" required maxlength="50" pattern="[A-Za-z0-9_-]+" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
        <datalist id="promotion-news-sources">
            @foreach ($knownSources as $source)
                <option value="{{ $source }}"></option>
            @endforeach
        </datalist>
        <p class="mt-1 text-xs text-gray-400">Ví dụ: coop, bhx, vib, grab, shopeefood... (có thể nhập nguồn mới)</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Danh mục <span class="text-red-500">*</span></label>
        <select name="category" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
            @foreach ($categories as $key => $label)
                <option value="{{ $key }}" @selected($value('category') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1">Tiêu đề</label>
        <input type="text" name="title" value="{{ $value('title') }}" maxlength="255" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
    </div>

    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1">Mô tả</label>
        <textarea name="description" rows="3" maxlength="2000" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">{{ $value('description') }}</textarea>
    </div>

    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1">URL ảnh <span class="text-red-500">*</span></label>
        <input type="url" name="image_url" value="{{ $value('image_url') }}" required maxlength="2048" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">URL ảnh mobile</label>
        <input type="url" name="mobile_image_url" value="{{ $value('mobile_image_url') }}" maxlength="2048" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">URL đích (landing)</label>
        <input type="url" name="landing_url" value="{{ $value('landing_url') }}" maxlength="2048" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Bắt đầu</label>
        <input type="datetime-local" name="start_at" value="{{ old('start_at', $dateValue('start_at')) }}" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Kết thúc</label>
        <input type="datetime-local" name="end_at" value="{{ old('end_at', $dateValue('end_at')) }}" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Thứ tự hiển thị</label>
        <input type="number" name="sort_order" value="{{ $value('sort_order', 0) }}" min="-100000" max="100000" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
    </div>

    <div class="flex items-center gap-2 pt-6">
        <input type="checkbox" name="is_active" value="1" id="is_active" @checked((bool) $value('is_active', true)) class="rounded border-gray-300 text-emerald-500 focus:ring-emerald-500">
        <label for="is_active" class="text-sm text-gray-700">Đang bật</label>
    </div>
</div>
