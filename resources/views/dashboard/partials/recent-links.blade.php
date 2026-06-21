<div>
    <div class="flex items-center gap-2 max-[390px]:mb-2 mb-3">
        <span class="max-[390px]:text-sm text-base">📋</span>
        <h3 class="max-[390px]:text-xs text-sm font-semibold text-gray-700">Link Hoàn Tiền Gần Đây</h3>
    </div>

    @if ($links->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-gray-200 max-[390px]:px-3 max-[390px]:py-6 px-4 py-8 text-center">
            <span class="max-[390px]:text-2xl text-3xl block max-[390px]:mb-1 mb-2">📋</span>
            <p class="max-[390px]:text-xs text-sm text-gray-400">Chưa có link nào</p>
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
                            <x-dashboard.status-badge :status="$link->status" />
                            <span class="max-[390px]:text-[10px] text-xs text-gray-400">{{ $link->created_at->diffForHumans() }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <button
                            type="button"
                            onclick="navigator.clipboard.writeText('{{ $link->affiliate_url ?? $link->original_url }}')"
                            class="max-[390px]:h-8 max-[390px]:w-8 h-9 w-9 flex items-center justify-center rounded-lg bg-gray-50 hover:bg-emerald-50 active:bg-emerald-100 text-gray-400 hover:text-emerald-600 transition-colors"
                            title="Sao chép link"
                        >
                            <svg class="max-[390px]:w-4 max-[390px]:h-4 w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                            </svg>
                        </button>
                        @if (!$link->is_pinned)
                            <form action="{{ route('link-requests.toggle-pin', $link) }}" method="POST">
                                @csrf
                                <button
                                    type="submit"
                                    class="max-[390px]:h-8 max-[390px]:w-8 h-9 w-9 flex items-center justify-center rounded-lg bg-gray-50 hover:bg-amber-50 active:bg-amber-100 text-gray-400 hover:text-amber-500 transition-colors"
                                    title="Ghim link"
                                >
                                    <svg class="max-[390px]:w-3.5 max-[390px]:h-3.5 w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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
