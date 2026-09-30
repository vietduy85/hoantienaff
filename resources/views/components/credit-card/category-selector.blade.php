@props([
    'name' => 'category_id',
    'id' => null,
    'selected' => null,
    'label' => '',
    'required' => false,
])

@php
    // Nền tảng dùng chung: Policy Engine → Tier Category Rule, form giao dịch, ...
    // Chỉ hiện danh mục hệ thống + danh mục riêng của user đang đăng nhập
    // (`CategoryService::selectableFor` → `Category::scopeSelectableBy`).
    $categories = app(\App\Services\CreditCard\CategoryService::class)->selectableFor((int) auth()->id());
    $systemCategories = $categories->filter(fn ($category) => $category->isSystem());
    $userCategories = $categories->reject(fn ($category) => $category->isSystem());
    $elementId = $id ?? $name;
    $selected = old($name, $selected);
@endphp

@if ($label)
    <label for="{{ $elementId }}" class="block text-sm font-medium text-gray-700 mb-1.5">{{ $label }}</label>
@endif

<select id="{{ $elementId }}"
        name="{{ $name }}"
        @required($required)
        class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
    <option value="">— Chọn danh mục chi tiêu —</option>

    <optgroup label="🏦 Danh mục hệ thống">
        @foreach ($systemCategories as $category)
            <option value="{{ $category->id }}" @selected((string) $selected === (string) $category->id)>
                {{ \App\Support\CreditCard\CategoryIcon::for($category) }} {{ $category->name }}
            </option>
        @endforeach
    </optgroup>

    @if ($userCategories->isNotEmpty())
        <optgroup label="👤 Danh mục của tôi">
            @foreach ($userCategories as $category)
                <option value="{{ $category->id }}" @selected((string) $selected === (string) $category->id)>
                    {{ \App\Support\CreditCard\CategoryIcon::for($category) }} {{ $category->name }}
                </option>
            @endforeach
        </optgroup>
    @endif
</select>