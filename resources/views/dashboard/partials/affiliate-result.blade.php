<div x-data="{ copied: false }">
    <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-2xl border border-emerald-200 max-[390px]:p-3 p-4">
        <div class="flex items-center gap-1.5 mb-2.5">
            <span class="max-[390px]:text-base text-lg">🎉</span>
            <h3 class="max-[390px]:text-sm text-base font-bold text-emerald-800">Link Hoàn Tiền</h3>
        </div>

        <div class="space-y-2.5">
            <div class="text-center bg-white rounded-xl border border-emerald-100 max-[390px]:px-3 max-[390px]:py-1.5 px-4 py-2">
                <p class="max-[390px]:text-[11px] text-xs text-gray-500">Ước tính hoàn tiền</p>
                <p class="max-[390px]:text-2xl text-3xl font-extrabold text-emerald-600 leading-tight">
                    ≈ {{ number_format($result->estimated_cashback, 0, ',', '.') }}đ
                </p>
            </div>

            <div class="flex items-center gap-1.5">
                <span class="max-[390px]:text-[11px] text-xs text-gray-500 shrink-0">🔗 Link hoàn tiền đã tạo</span>
            </div>

            <div class="flex gap-2">
                <button
                    type="button"
                    @click="
                        navigator.clipboard.writeText('{{ $result->affiliate_url }}');
                        copied = true;
                        setTimeout(() => copied = false, 2000);
                    "
                    class="flex-1 max-[390px]:h-11 h-12 bg-white hover:bg-emerald-50 active:bg-emerald-100 text-emerald-700 font-semibold max-[390px]:text-xs text-sm rounded-xl border-2 border-emerald-200 transition-all duration-150 flex items-center justify-center gap-1.5"
                >
                    <span x-show="!copied" class="max-[390px]:text-base text-lg">📋</span>
                    <span x-show="!copied">Sao chép</span>
                    <span x-show="copied" x-cloak>✅</span>
                    <span x-show="copied" x-cloak class="font-bold">Đã sao chép!</span>
                </button>

                <a
                    href="{{ $result->affiliate_url }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex-1 max-[390px]:h-11 h-12 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-bold max-[390px]:text-xs text-sm rounded-xl transition-all duration-150 flex items-center justify-center gap-1.5"
                >
                    <span class="max-[390px]:text-base text-lg">🛒</span>
                    <span>Mở sản phẩm</span>
                </a>
            </div>
        </div>
    </div>
</div>
