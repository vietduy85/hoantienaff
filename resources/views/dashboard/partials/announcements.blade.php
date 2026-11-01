{{-- Thông báo ngắn do admin tạo (menu "Thông báo trang chủ"), nằm ngay trên dòng mời chọn thẻ.
     User bấm ✕ để ẩn trên máy mình; admin sửa nội dung thì khoá đổi → hiện lại. --}}
@foreach ($announcements ?? [] as $announcement)
    @php
        $colors = $announcement->colorClasses();
        [$before, $after] = $announcement->contentParts();
    @endphp
    <div x-data="{ hidden: localStorage.getItem(@js($announcement->dismissKey())) === '1' }"
         x-show="!hidden"
         class="flex items-start gap-3 border rounded-xl max-[390px]:px-3 px-4 py-3 {{ $colors['box'] }}"
         data-testid="announcement">
        @if (filled($announcement->icon))
            <span class="text-xl shrink-0 leading-none mt-0.5">{{ $announcement->icon }}</span>
        @endif
        <p class="text-sm leading-snug flex-1 min-w-0 break-words">
            @if (filled($announcement->url))
                {{ $before }}<a href="{{ route('announcements.click', $announcement) }}"
                   @if ($announcement->open_new_tab) target="_blank" rel="noopener" @endif
                   class="font-semibold underline {{ $colors['link'] }}">{{ $announcement->linkLabel() }}</a>{{ $after }}
            @else
                {{ $announcement->plainContent() }}
            @endif
        </p>
        <button type="button"
                x-on:click="hidden = true; localStorage.setItem(@js($announcement->dismissKey()), '1')"
                class="shrink-0 -mr-1 -mt-0.5 p-1 opacity-50 hover:opacity-100"
                aria-label="Ẩn thông báo">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
@endforeach
