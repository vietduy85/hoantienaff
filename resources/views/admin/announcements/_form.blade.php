@use('App\Models\Announcement')
@php
    $value = fn (string $field, $default = null) => old($field, $announcement->{$field} ?? $default);
    $dateValue = fn (string $field) => old($field, $announcement->{$field}?->format('Y-m-d\TH:i') ?? '');
    $icons = ['📢', '🔥', '🎁', '⚡', '💰', '📣', '🎉', '⭐', '❗'];
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

<div x-data="{
        content: @js((string) $value('content', '')),
        linkText: @js((string) $value('link_text', '')),
        url: @js((string) $value('url', '')),
        icon: @js((string) $value('icon', '')),
        color: @js((string) $value('color', 'amber')),
        colors: @js(Announcement::COLORS),
        get parts() {
            const i = this.content.indexOf('{link}');
            if (i === -1) return [this.content.trimEnd() + ' ', ''];
            return [this.content.slice(0, i), this.content.slice(i + 6).replaceAll('{link}', '')];
        },
        get label() { return this.linkText.trim() || @js(Announcement::DEFAULT_LINK_TEXT); },
        get box() { return (this.colors[this.color] || this.colors.amber).box; },
        get linkClass() { return (this.colors[this.color] || this.colors.amber).link; },
        insertLink() {
            const el = this.$refs.content;
            const at = el.selectionStart ?? this.content.length;
            this.content = this.content.slice(0, at) + '{link}' + this.content.slice(el.selectionEnd ?? at);
            this.$nextTick(() => { el.focus(); el.selectionStart = el.selectionEnd = at + 6; });
        },
     }" class="space-y-5">

    {{-- Xem trước --}}
    <div>
        <p class="text-xs font-medium text-gray-500 mb-1.5">Xem trước trên trang chủ</p>
        <div class="max-w-lg flex items-start gap-3 border rounded-xl px-4 py-3" :class="box" data-testid="announcement-preview">
            <span class="text-xl shrink-0 leading-none mt-0.5" x-show="icon" x-text="icon"></span>
            <p class="text-sm leading-snug flex-1 min-w-0 break-words">
                <template x-if="url.trim()">
                    <span><span x-text="parts[0]"></span><span class="font-semibold underline" :class="linkClass" x-text="label"></span><span x-text="parts[1]"></span></span>
                </template>
                <template x-if="!url.trim()">
                    <span x-text="content.replaceAll('{link}', '').trim() || 'Nhập nội dung…'"></span>
                </template>
            </p>
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nội dung <span class="text-red-500">*</span></label>
        <textarea name="content" rows="3" maxlength="500" required x-model="content" x-ref="content"
                  placeholder="VD: 🔥 Shopee x3 hoàn tiền hôm nay! Theo dõi Facebook Hoàn Tiền để lấy link {link}"
                  class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg"></textarea>
        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-400">
            <button type="button" x-on:click="insertLink()" class="px-2 py-0.5 rounded bg-gray-100 hover:bg-gray-200 text-gray-700 font-mono">+ {link}</button>
            <span>Đặt <code>{link}</code> ở chỗ muốn hiện chữ link. Không đặt thì chữ link nằm cuối câu.</span>
            <span class="ml-auto" x-text="content.length + '/500'"></span>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Chữ link</label>
            <input type="text" name="link_text" maxlength="60" x-model="linkText" placeholder="{{ Announcement::DEFAULT_LINK_TEXT }}"
                   class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">URL khi bấm</label>
            <input type="url" name="url" maxlength="2048" x-model="url" placeholder="https://facebook.com/…"
                   class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
            <p class="mt-1 text-xs text-gray-400">Để trống: thông báo chỉ là chữ, không có link.</p>
        </div>
    </div>

    <label class="flex items-center gap-2">
        <input type="checkbox" name="open_new_tab" value="1" @checked((bool) $value('open_new_tab', true)) class="rounded border-gray-300 text-emerald-500 focus:ring-emerald-500">
        <span class="text-sm text-gray-700">Mở link trong tab mới (khuyên dùng cho link Facebook/Instagram)</span>
    </label>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Biểu tượng</label>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($icons as $emoji)
                    <button type="button" x-on:click="icon = @js($emoji)"
                            class="w-9 h-9 rounded-lg border text-lg"
                            :class="icon === @js($emoji) ? 'border-emerald-500 bg-emerald-50' : 'border-gray-200 bg-white'">{{ $emoji }}</button>
                @endforeach
                <input type="text" name="icon" maxlength="16" x-model="icon" placeholder="khác"
                       class="w-16 px-2 py-1.5 text-center text-sm border border-gray-300 rounded-lg">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Màu</label>
            <div class="flex flex-wrap gap-1.5">
                @foreach (Announcement::COLORS as $key => $c)
                    <label class="cursor-pointer">
                        <input type="radio" name="color" value="{{ $key }}" x-model="color" class="sr-only">
                        <span class="inline-block px-3 py-1.5 rounded-lg border text-xs font-medium {{ $c['box'] }}"
                              :class="color === @js($key) ? 'ring-2 ring-offset-1 ring-gray-500' : ''">{{ $c['label'] }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Bắt đầu hiện</label>
            <input type="datetime-local" name="start_at" value="{{ $dateValue('start_at') }}" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
            <p class="mt-1 text-xs text-gray-400">Trống = hiện ngay</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tự ẩn lúc</label>
            <input type="datetime-local" name="end_at" value="{{ $dateValue('end_at') }}" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
            <p class="mt-1 text-xs text-gray-400">Trống = không hạn. VD: hết đợt x3 lúc 23:59</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Thứ tự</label>
            <input type="number" name="sort_order" value="{{ $value('sort_order', 0) }}" min="-1000" max="1000" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg">
            <p class="mt-1 text-xs text-gray-400">Số nhỏ hiện trước</p>
        </div>
    </div>

    <label class="flex items-center gap-2 p-3 rounded-xl bg-emerald-50 border border-emerald-100">
        <input type="checkbox" name="is_active" value="1" @checked((bool) $value('is_active', true)) class="rounded border-gray-300 text-emerald-500 focus:ring-emerald-500">
        <span class="text-sm font-medium text-emerald-900">Hiển thị trên trang chủ</span>
    </label>
</div>
