<div>
    <div class="flex items-center gap-2 max-[390px]:mb-2 mb-3">
        <span class="max-[390px]:text-sm text-base">📌</span>
        <h3 class="max-[390px]:text-xs text-sm font-semibold text-gray-700">Link Ghim</h3>
        <span class="max-[390px]:text-[10px] text-xs text-gray-400">(tối đa 5)</span>
    </div>

    @if ($links->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-gray-200 max-[390px]:px-3 max-[390px]:py-6 px-4 py-8 text-center">
            <span class="max-[390px]:text-2xl text-3xl block max-[390px]:mb-1 mb-2">📌</span>
            <p class="max-[390px]:text-xs text-sm text-gray-400">Chưa có link ghim</p>
        </div>
    @else
        <div class="space-y-1.5">
            @foreach ($links as $link)
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 max-[390px]:px-3 max-[390px]:py-2 px-4 py-2.5 flex items-center gap-2">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <span class="font-bold max-[390px]:text-sm text-base text-emerald-600">
                                {{ number_format($link->estimated_cashback ?? 0, 0, ',', '.') }}đ
                            </span>
                            <x-dashboard.platform-badge :platform="$link->platform" />
                        </div>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span class="max-[390px]:text-[10px] text-xs text-gray-400">{{ $link->pinned_at->diffForHumans() }}</span>
                        </div>
                    </div>
                    <form action="{{ route('link-requests.toggle-pin', $link) }}" method="POST" class="shrink-0">
                        @csrf
                        <button
                            type="submit"
                            class="max-[390px]:h-8 max-[390px]:w-8 h-9 w-9 flex items-center justify-center rounded-lg bg-amber-50 hover:bg-amber-100 active:bg-amber-200 text-amber-500 transition-colors"
                            title="Bỏ ghim"
                        >
                            <svg class="max-[390px]:w-4 max-[390px]:h-4 w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/>
                            </svg>
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
</div>
