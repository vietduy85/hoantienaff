{{--
    Ô chọn TÌM KIẾM ĐƯỢC — nền tảng dùng chung cho ô "Thẻ tín dụng" và "Danh mục"
    của form nhập/sửa giao dịch.

    Vì sao KHÔNG dùng `<select>` native:
      - Danh mục + thẻ có thể lên vài chục dòng, cuộn tìm trên điện thoại rất chậm.
      - Bàn phím mềm của iOS/Android che ô chọn native dựng ở giữa màn hình, không
        kiểm soát được chiều cao; một dropdown tự dựng bám theo viewport thì kiểm
        soát được (`max-h-[min(16rem,45vh)]`).
      - Cần hiển thị PHỤ (ngân hàng + 4 số cuối của thẻ / loại danh mục) mà option
        native không làm nổi.

    CÁCH DÙNG (giá trị vẫn hai chiều qua `x-model` ở nơi gọi):

        <x-credit-card.searchable-select
            id="tx-category"
            x-model="form.category_id"
            :options="$categoryOptions"
            placeholder="— Chọn danh mục —" />

    `x-modelable="value"` trên phần tử gốc + `x-model` của nơi gọi tạo cặp
    entangle hai chiều: component sửa `value` ⇒ nơi gọi cập nhật `form.*_id`.
    BẮT BUỘC cả hai directive nằm CÙNG phần tử: `x-modelable` gỡ input-listener
    mặc định của `x-model` (nếu không, mỗi ký tự gõ trong ô tìm sẽ rơi vào
    `value` và ghi đè id đang chọn).
--}}
@props([
    'id' => null,
    'options' => [],
    'placeholder' => 'Chọn…',
    'searchPlaceholder' => 'Tìm kiếm…',
    'emptyText' => 'Không tìm thấy kết quả nào khớp.',
    'clearable' => false,
    'clearLabel' => 'Bỏ chọn',
    'testid' => null,
])

@php
    $config = [
        'options' => array_values($options),
        'placeholder' => $placeholder,
        'searchPlaceholder' => $searchPlaceholder,
        'emptyText' => $emptyText,
        'clearable' => (bool) $clearable,
        'clearLabel' => $clearLabel,
    ];
@endphp

<div
    {{ $attributes->merge(['class' => 'relative']) }}
    x-data="ccSearchableSelect(@js($config))"
    x-modelable="value"
    @click.outside="close()"
>
    {{-- Trigger: mở dropdown. Id nằm ở ĐÂY (không phải ô tìm) để `<label for>`
         và test HTML trỏ đúng điều khiển. --}}
    <button
        type="button"
        @if ($id !== null) id="{{ $id }}" @endif
        @if ($testid !== null) data-testid="{{ $testid }}" @endif
        @click="toggle()"
        @keydown.arrow-down.prevent="openPicker(); move(1)"
        @keydown.arrow-up.prevent="openPicker(); move(-1)"
        @keydown.enter.prevent="chooseHighlighted()"
        @keydown.escape.stop="close()"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="listbox"
        class="w-full min-h-12 rounded-xl border border-gray-300 bg-white text-base px-4 py-3 shadow-sm flex items-center justify-between gap-2 text-left focus:border-emerald-500 focus:ring-emerald-500"
    >
        <span class="min-w-0 truncate" :class="hasValue ? 'text-gray-800' : 'text-gray-400'" x-text="selectedLabel"></span>
        <span class="shrink-0 text-gray-400" aria-hidden="true">
            <span x-show="! open">▾</span>
            <span x-show="open" x-cloak>▴</span>
        </span>
    </button>

    {{-- Dropdown: absolute trong khối `relative` nên không bị container cắt.
         `max-h` co theo viewport để bàn phím mobile mở vẫn thấy danh sách. --}}
    <div x-show="open"
         x-cloak
         class="absolute z-50 left-0 right-0 mt-1 rounded-xl border border-gray-200 bg-white shadow-lg overflow-hidden">
        <div class="p-2 border-b border-gray-100">
            <input type="search"
                   x-ref="search"
                   x-model="query"
                   @keydown.arrow-down.prevent="move(1)"
                   @keydown.arrow-up.prevent="move(-1)"
                   @keydown.enter.prevent="chooseHighlighted()"
                   @keydown.escape.stop="close()"
                   placeholder="{{ $searchPlaceholder }}"
                   enterkeyhint="search"
                   autocomplete="off"
                   autocapitalize="off"
                   autocorrect="off"
                   spellcheck="false"
                   class="w-full min-h-12 rounded-lg border border-gray-300 text-base px-3 py-2 focus:border-emerald-500 focus:ring-emerald-500">
        </div>

        <ul role="listbox" class="max-h-[min(16rem,45vh)] overflow-y-auto overscroll-contain py-1">
            <template x-for="(option, index) in filtered" :key="option.id">
                <li role="option" :aria-selected="isSelected(option) ? 'true' : 'false'">
                    <button type="button"
                            @click="pick(option)"
                            @mouseenter="highlight = index"
                            class="w-full min-h-12 px-4 py-2.5 text-left flex items-center justify-between gap-2"
                            :class="isSelected(option)
                                ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                : (highlight === index ? 'bg-gray-50 text-gray-700' : 'text-gray-700 active:bg-gray-50')">
                        <span class="min-w-0 truncate" x-text="option.label"></span>
                        <span x-show="option.sublabel" class="shrink-0 text-xs text-gray-400" x-text="option.sublabel"></span>
                    </button>
                </li>
            </template>

            <li x-show="filtered.length === 0" x-cloak>
                <p class="px-4 py-3 text-sm text-gray-400" x-text="emptyText"></p>
            </li>
        </ul>

        <div x-show="clearable && hasValue" x-cloak class="border-t border-gray-100">
            <button type="button" @click="clear()"
                    class="w-full min-h-12 px-4 py-2.5 text-left text-gray-500 active:bg-gray-50"
                    x-text="clearLabel"></button>
        </div>
    </div>
</div>

@once
    <script>
        /**
         * Factory cho ô chọn tìm kiếm được — tách biệt hoàn toàn với nghiệp vụ
         * giao dịch: nó chỉ giữ `value` (id đang chọn) và danh sách `options`.
         *
         * Giá trị option là SỐ (id) hoặc chuỗi rỗng; so khớp bằng `String()` để
         * chịu được cả hai (Blade có thể render `5` hoặc `"5"`).
         *
         * Tìm kiếm BỎ DẤU (NFD + `đ`→`d`) nên gõ "ca phe" vẫn ra "Cà phê".
         */
        function ccSearchableSelect(config) {
            const options = config.options ?? [];

            return {
                options,
                value: null,
                open: false,
                query: '',
                highlight: 0,

                placeholder: config.placeholder ?? '',
                emptyText: config.emptyText ?? '',
                clearable: config.clearable === true,
                clearLabel: config.clearLabel ?? 'Bỏ chọn',

                normalize(value) {
                    return String(value ?? '')
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .replace(/đ/g, 'd')
                        .replace(/Đ/g, 'D')
                        .toLowerCase()
                        .trim();
                },

                get keyword() {
                    return this.normalize(this.query);
                },

                get filtered() {
                    if (this.keyword === '') {
                        return this.options;
                    }

                    return this.options.filter((option) =>
                        this.normalize(option.label).includes(this.keyword)
                        || this.normalize(option.sublabel).includes(this.keyword));
                },

                sameId(a, b) {
                    return a !== null && a !== undefined && b !== null && b !== undefined
                        && String(a) === String(b);
                },

                isSelected(option) {
                    return this.sameId(option.id, this.value);
                },

                get hasValue() {
                    return this.value !== null && this.value !== undefined && this.value !== '';
                },

                get selectedLabel() {
                    const selected = this.options.find((option) => this.isSelected(option));

                    return selected ? selected.label : this.placeholder;
                },

                openPicker() {
                    this.open = true;
                    this.query = '';

                    const selectedIndex = this.options.findIndex((option) => this.isSelected(option));
                    this.highlight = selectedIndex > -1 ? selectedIndex : 0;

                    this.$nextTick(() => this.$refs.search?.focus());
                },

                toggle() {
                    this.open ? this.close() : this.openPicker();
                },

                close() {
                    this.open = false;
                    this.query = '';
                },

                move(delta) {
                    const count = this.filtered.length;

                    if (count === 0) {
                        return;
                    }

                    this.highlight = (this.highlight + delta + count) % count;
                },

                chooseHighlighted() {
                    if (!this.open) {
                        this.openPicker();

                        return;
                    }

                    const list = this.filtered;

                    if (list.length === 0) {
                        return;
                    }

                    const index = Math.min(Math.max(this.highlight, 0), list.length - 1);
                    this.pick(list[index]);
                },

                pick(option) {
                    this.value = option.id;
                    this.close();
                },

                clear() {
                    this.value = null;
                    this.close();
                },
            };
        }
    </script>
@endonce
