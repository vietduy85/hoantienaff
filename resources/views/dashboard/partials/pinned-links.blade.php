<div>
    <div class="flex items-center gap-2 mb-3">
        <span class="text-base">📌</span>
        <h3 class="text-sm font-semibold text-gray-700">Link Ghim</h3>
        <span class="text-xs text-gray-400">(tối đa 5)</span>
    </div>

    @if ($links->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-gray-200 px-4 py-8 text-center">
            <span class="text-3xl block mb-2">📌</span>
            <p class="text-sm text-gray-400">Chưa có link ghim</p>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($links as $link)
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-4 py-3 flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-gray-900 truncate">
                                {{ Str::limit($link->affiliate_url ?? $link->original_url, 40) }}
                            </p>
                            <x-dashboard.platform-badge :platform="$link->platform" />
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-400">{{ $link->pinned_at->diffForHumans() }}</span>
                        </div>
                    </div>
                    <form action="{{ route('link-requests.toggle-pin', $link) }}" method="POST" class="shrink-0">
                        @csrf
                        <button
                            type="submit"
                            class="h-10 w-10 flex items-center justify-center rounded-lg bg-amber-50 hover:bg-amber-100 active:bg-amber-200 text-amber-500 transition-colors"
                            title="Bỏ ghim"
                        >
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/>
                            </svg>
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
</div>
