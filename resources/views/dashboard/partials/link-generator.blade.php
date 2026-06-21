<div class="bg-white rounded-2xl shadow-md border-2 border-emerald-400 max-[390px]:p-3 p-4 -mx-1">
    <div class="text-center max-[390px]:mb-2 mb-2.5">
        <h2 class="font-bold text-gray-900 max-[390px]:text-base text-lg">Tạo Link Hoàn Tiền</h2>
        <p class="max-[390px]:text-[11px] text-xs text-gray-400 mt-0.5">
            Hỗ trợ Shopee • Lazada • TikTok Shop • Tiki
        </p>
    </div>

    @if (session('success'))
        <div class="bg-emerald-50 border border-emerald-200 rounded-xl max-[390px]:px-2.5 max-[390px]:py-1.5 px-3 py-2 max-[390px]:mb-2 mb-2.5 flex items-start gap-1.5">
            <span class="max-[390px]:text-sm text-base shrink-0">✅</span>
            <p class="max-[390px]:text-[11px] text-xs text-emerald-800 font-medium">{{ session('success') }}</p>
        </div>
    @endif

    @if (session('error'))
        <div class="bg-red-50 border border-red-200 rounded-xl max-[390px]:px-2.5 max-[390px]:py-1.5 px-3 py-2 max-[390px]:mb-2 mb-2.5 flex items-start gap-1.5">
            <span class="max-[390px]:text-sm text-base shrink-0">❌</span>
            <p class="max-[390px]:text-[11px] text-xs text-red-700 font-medium">{{ session('error') }}</p>
        </div>
    @endif

    <form action="{{ route('link-requests.store') }}" method="POST" class="space-y-2.5">
        @csrf

        <div>
            <label for="original_url" class="sr-only">Dán link sản phẩm</label>
            <input
                id="original_url"
                name="original_url"
                type="url"
                required
                placeholder="Dán link sản phẩm..."
                value="{{ old('original_url') }}"
                class="block w-full max-[390px]:h-11 h-12 max-[390px]:px-3 px-3.5 max-[390px]:text-sm text-base border-2 border-gray-200 rounded-xl focus:border-emerald-400 focus:ring-emerald-400 transition placeholder:text-gray-400"
            >
            @error('original_url')
                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <button
            type="submit"
            class="w-full max-[390px]:h-12 h-13 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white max-[390px]:text-sm text-base font-bold rounded-xl shadow-lg shadow-emerald-200 hover:shadow-emerald-300 transition-all duration-150 flex items-center justify-center gap-2"
        >
            <span class="max-[390px]:text-lg text-xl">🚀</span>
            <span>Tạo Link Ngay</span>
        </button>
    </form>
</div>
