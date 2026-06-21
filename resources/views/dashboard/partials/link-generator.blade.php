<div class="bg-white rounded-2xl shadow-md border-2 border-emerald-400 p-6 -mx-1">
    <div class="text-center mb-5">
        <div class="text-3xl mb-2">🔗</div>
        <h2 class="text-lg font-bold text-gray-900">Tạo Link Hoàn Tiền</h2>
        <p class="text-sm text-gray-500 mt-1">
            Hỗ trợ: Shopee, Lazada, TikTok Shop, Tiki
        </p>
    </div>

    @if (session('success'))
        <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 mb-4 flex items-start gap-3">
            <span class="text-emerald-500 text-lg shrink-0 mt-0.5">✅</span>
            <p class="text-sm text-emerald-800 font-medium">{{ session('success') }}</p>
        </div>
    @endif

    <form action="{{ route('link-requests.store') }}" method="POST" class="space-y-4">
        @csrf

        <div>
            <label for="original_url" class="sr-only">Dán link sản phẩm</label>
            <input
                id="original_url"
                name="original_url"
                type="url"
                required
                placeholder="Dán link sản phẩm Shopee, Lazada, TikTok Shop, Tiki..."
                value="{{ old('original_url') }}"
                class="block w-full h-12 px-4 text-base border-2 border-gray-200 rounded-xl focus:border-emerald-400 focus:ring-emerald-400 transition placeholder:text-gray-400"
            >
            @error('original_url')
                <p class="text-sm text-red-500 mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        <button
            type="submit"
            class="w-full h-14 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-base font-bold rounded-xl shadow-lg shadow-emerald-200 hover:shadow-emerald-300 transition-all duration-150 flex items-center justify-center gap-2"
        >
            <span class="text-xl">🚀</span>
            <span>Tạo Link Ngay</span>
        </button>
    </form>

    <div class="flex flex-wrap justify-center gap-2 mt-4">
        <span class="inline-flex items-center gap-1 px-3 py-1 bg-orange-50 text-orange-700 text-xs font-medium rounded-full">
            <span class="w-1.5 h-1.5 rounded-full bg-orange-500"></span>
            Shopee
        </span>
        <span class="inline-flex items-center gap-1 px-3 py-1 bg-blue-50 text-blue-700 text-xs font-medium rounded-full">
            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
            Lazada
        </span>
        <span class="inline-flex items-center gap-1 px-3 py-1 bg-pink-50 text-pink-700 text-xs font-medium rounded-full">
            <span class="w-1.5 h-1.5 rounded-full bg-pink-500"></span>
            TikTok Shop
        </span>
        <span class="inline-flex items-center gap-1 px-3 py-1 bg-cyan-50 text-cyan-700 text-xs font-medium rounded-full">
            <span class="w-1.5 h-1.5 rounded-full bg-cyan-500"></span>
            Tiki
        </span>
    </div>
</div>
