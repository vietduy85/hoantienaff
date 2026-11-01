<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                📢 {{ __('Thông báo trang chủ') }}
            </h2>
            <a href="{{ route('admin.announcements.create') }}" class="shrink-0 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
                + Thêm thông báo
            </a>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-4xl mx-auto space-y-4">
        @if (session('success'))
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <p class="text-sm text-gray-500">
            Hiện ngay trên dòng "Bạn muốn được hoàn tiền nhiều hơn?" ở trang chủ, tối đa
            {{ \App\Models\Announcement::MAX_VISIBLE }} thông báo theo thứ tự (số nhỏ lên trước).
            Thông báo hết hạn tự ẩn.
        </p>

        @forelse ($announcements as $announcement)
            @php
                $colors = $announcement->colorClasses();
                [$before, $after] = $announcement->contentParts();
                $status = $announcement->statusLabel();
                $onHome = in_array($announcement->id, $visibleIds, true);
            @endphp
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-3" data-testid="announcement-row">
                {{-- Xem trước đúng như trên trang chủ --}}
                <div class="flex items-start gap-3 border rounded-xl px-4 py-3 {{ $colors['box'] }} {{ $onHome ? '' : 'opacity-60' }}">
                    @if (filled($announcement->icon))
                        <span class="text-xl shrink-0 leading-none mt-0.5">{{ $announcement->icon }}</span>
                    @endif
                    <p class="text-sm leading-snug flex-1 min-w-0 break-words">
                        @if (filled($announcement->url))
                            {{ $before }}<span class="font-semibold underline {{ $colors['link'] }}">{{ $announcement->linkLabel() }}</span>{{ $after }}
                        @else
                            {{ $announcement->plainContent() }}
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                    @php
                        $badge = match ($status) {
                            'Đang hiện' => $onHome ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800',
                            'Chờ tới giờ' => 'bg-sky-100 text-sky-800',
                            default => 'bg-gray-100 text-gray-600',
                        };
                    @endphp
                    <span class="inline-block px-2 py-0.5 rounded-full font-medium {{ $badge }}">
                        {{ $status === 'Đang hiện' && ! $onHome ? 'Bật nhưng vượt quá '.\App\Models\Announcement::MAX_VISIBLE.' tin' : $status }}
                    </span>
                    <span>Thứ tự: <b class="text-gray-700">{{ $announcement->sort_order }}</b></span>
                    <span>👆 <b class="text-gray-700">{{ number_format($announcement->click_count, 0, ',', '.') }}</b> lượt bấm</span>
                    @if ($announcement->start_at || $announcement->end_at)
                        <span>
                            🗓 {{ $announcement->start_at?->format('d/m H:i') ?? '…' }}
                            → {{ $announcement->end_at?->format('d/m/Y H:i') ?? 'không hạn' }}
                        </span>
                    @endif
                    @if ($announcement->url)
                        <span class="truncate max-w-full sm:max-w-xs" title="{{ $announcement->url }}">🔗 {{ $announcement->url }}</span>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.announcements.toggle', $announcement) }}">
                        @csrf
                        @if ($announcement->is_active)
                            <button type="submit" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition-colors" data-testid="announcement-toggle">
                                🙈 Ẩn khỏi trang chủ
                            </button>
                        @else
                            <button type="submit" class="px-3 py-1.5 bg-emerald-100 hover:bg-emerald-200 text-emerald-800 text-xs font-medium rounded-lg transition-colors" data-testid="announcement-toggle">
                                👁 Hiển thị
                            </button>
                        @endif
                    </form>
                    <a href="{{ route('admin.announcements.edit', $announcement) }}" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition-colors">✏️ Sửa</a>
                    <form method="POST" action="{{ route('admin.announcements.destroy', $announcement) }}" onsubmit="return confirm('Xoá thông báo này?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 text-xs font-medium rounded-lg transition-colors">Xoá</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center text-gray-400 text-sm">
                Chưa có thông báo nào. Bấm "+ Thêm thông báo" để tạo.
            </div>
        @endforelse
    </div>
</x-app-layout>
