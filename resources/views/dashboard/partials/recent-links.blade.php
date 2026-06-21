<div>
    <div class="flex items-center gap-2 mb-3">
        <span class="text-base">📋</span>
        <h3 class="text-sm font-semibold text-gray-700">Link Gần Đây</h3>
    </div>

    <div class="space-y-2">
        @foreach ($links as $link)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-4 py-3 flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 mb-0.5">
                        <p class="text-sm font-medium text-gray-900 truncate">
                            Link sản phẩm
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-dashboard.status-badge :status="$link->status" />
                        <span class="text-xs text-gray-400">{{ $link->created_at->diffForHumans() }}</span>
                    </div>
                </div>
                <x-dashboard.platform-badge :platform="$link->platform" />
            </div>
        @endforeach
    </div>
</div>
