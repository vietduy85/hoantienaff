<div>
    <div class="flex items-center gap-2 mb-3">
        <span class="text-base">📋</span>
        <h3 class="text-sm font-semibold text-gray-700">Link Gần Đây</h3>
    </div>

    @if ($links->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-gray-200 px-4 py-8 text-center">
            <span class="text-3xl block mb-2">📋</span>
            <p class="text-sm text-gray-400">Chưa có link nào</p>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($links as $link)
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-4 py-3 flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-gray-900 truncate">
                                {{ Str::limit($link->original_url, 50) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-dashboard.status-badge :status="$link->status" />
                            <span class="text-xs text-gray-400">{{ $link->created_at->diffForHumans() }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <x-dashboard.platform-badge :platform="$link->platform" />
                        @if (!$link->is_pinned)
                            <form action="{{ route('link-requests.toggle-pin', $link) }}" method="POST">
                                @csrf
                                <button
                                    type="submit"
                                    class="h-9 w-9 flex items-center justify-center rounded-lg bg-gray-50 hover:bg-amber-50 active:bg-amber-100 text-gray-400 hover:text-amber-500 transition-colors"
                                    title="Ghim link"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/>
                                    </svg>
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
