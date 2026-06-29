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
                        @if ($link->product_name)
                            @if ($link->affiliate_url)
                                <a href="{{ $link->affiliate_url }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="text-emerald-600 hover:text-emerald-700 hover:underline cursor-pointer truncate whitespace-nowrap overflow-hidden text-ellipsis max-[390px]:text-sm text-sm block">
                                    {{ $link->product_name }}
                                </a>
                            @else
                                <span class="text-gray-400 truncate whitespace-nowrap overflow-hidden text-ellipsis max-[390px]:text-sm text-sm block">
                                    {{ $link->product_name }}
                                </span>
                            @endif
                        @endif
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        @if ($link->affiliate_url)
                            <a href="{{ $link->affiliate_url }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="max-[390px]:text-[11px] max-[390px]:px-2 max-[390px]:h-7 text-xs px-3 h-8 flex items-center rounded-lg bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white transition-colors font-medium whitespace-nowrap">
                                Mua ngay
                            </a>
                        @endif
                        <form action="{{ route('link-requests.toggle-pin', $link) }}" method="POST">
                            @csrf
                            <button type="submit"
                                    class="max-[390px]:text-[11px] max-[390px]:px-1.5 max-[390px]:h-7 text-xs px-2 h-8 flex items-center gap-1 rounded-lg bg-amber-50 hover:bg-amber-100 active:bg-amber-200 text-amber-500 transition-colors whitespace-nowrap"
                                    title="Bỏ ghim">
                                <svg class="max-[390px]:w-3 max-[390px]:h-3 w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/>
                                </svg>
                                Bỏ ghim
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
