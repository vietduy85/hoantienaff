<div>
    <div class="flex items-center gap-2 mb-3">
        <span class="text-base">📌</span>
        <h3 class="text-sm font-semibold text-gray-700">Link Ghim</h3>
        <span class="text-xs text-gray-400">(tối đa 5)</span>
    </div>

    <div class="space-y-2">
        @foreach ($links as $link)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-4 py-3 flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-gray-900 truncate">
                            Link sản phẩm
                        </p>
                        <x-dashboard.platform-badge :platform="$link->platform" />
                    </div>
                    <p class="text-xs text-gray-400 truncate">{{ $link->affiliate_url ?? $link->original_url }}</p>
                </div>
                <button
                    type="button"
                    onclick="navigator.clipboard.writeText('{{ $link->affiliate_url ?? $link->original_url }}')"
                    class="shrink-0 h-10 w-10 flex items-center justify-center rounded-lg bg-gray-50 hover:bg-gray-100 active:bg-gray-200 text-gray-500 transition-colors"
                    title="Sao chép link"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
                    </svg>
                </button>
            </div>
        @endforeach
    </div>
</div>
