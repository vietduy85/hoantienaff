<div class="bg-white rounded-xl shadow-sm border border-gray-100 max-[390px]:px-3 px-4 py-4 space-y-3" data-testid="wallet-summary">
    <div class="flex items-center justify-between">
        <span class="text-sm font-medium text-gray-500">💰 Ví tiền</span>
        <a href="{{ route('wallet.index') }}" class="text-xs text-emerald-600 hover:underline">Chi tiết</a>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <div class="text-xs text-gray-400">Số dư khả dụng</div>
            <div class="text-emerald-600 font-extrabold text-2xl tracking-tight">{{ number_format(floor($walletAvailable), 0, ',', '.') }}<span class="text-sm">đ</span></div>
        </div>
        <div>
            <div class="text-xs text-gray-400">🕒 Tiền chờ về</div>
            <div class="text-amber-500 font-bold text-xl tracking-tight">{{ number_format(floor($walletPending), 0, ',', '.') }}<span class="text-sm">đ</span></div>
        </div>
    </div>
    <a href="{{ route('wallet.index', ['rut' => 1]) }}"
       class="block w-full h-11 leading-[2.75rem] text-center bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm">
        Rút tiền
    </a>
</div>
