<div class="bg-white rounded-2xl shadow-sm border border-emerald-100 p-5">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-full bg-emerald-100 flex items-center justify-center shrink-0">
            <span class="text-2xl">👋</span>
        </div>
        <div class="min-w-0">
            <h1 class="text-lg font-bold text-gray-900 truncate">
                Xin chào, {{ auth()->user()->name }}
            </h1>
            <p class="text-sm text-gray-500 mt-0.5">
                Dán link sản phẩm để nhận hoàn tiền ngay!
            </p>
        </div>
    </div>
</div>
