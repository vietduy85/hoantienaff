<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Debug Provider</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 font-sans antialiased">
    <div class="max-w-lg mx-auto px-4 py-8">
        <h1 class="text-xl font-bold text-gray-900 mb-1">🔧 Debug Provider</h1>
        <p class="text-sm text-gray-400 mb-6">Kiểm tra phát hiện nền tảng và provider</p>

        <form method="POST" action="{{ url('debug/provider') }}" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
            @csrf
            <label for="url" class="block text-sm font-semibold text-gray-700 mb-1.5">Nhập URL sản phẩm</label>
            <input
                type="url"
                name="url"
                id="url"
                required
                placeholder="https://shopee.vn/abc"
                value="{{ old('url', $url ?? '') }}"
                class="block w-full h-11 px-3.5 text-sm border-2 border-gray-200 rounded-xl focus:border-emerald-400 focus:ring-emerald-400 transition placeholder:text-gray-400 mb-3"
            >
            <button
                type="submit"
                class="w-full h-11 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-bold text-sm rounded-xl transition-all flex items-center justify-center gap-2"
            >
                🔍 Kiểm tra
            </button>
        </form>

        @if ($result || $error)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 space-y-4">
                <div>
                    <p class="text-xs text-gray-400 font-medium mb-1">URL đã nhập</p>
                    <p class="text-sm text-gray-700 font-mono break-all">{{ $url }}</p>
                </div>

                <div class="pt-3 border-t border-gray-100">
                    <p class="text-xs text-gray-400 font-medium mb-1">Platform detected</p>
                    <p class="text-base font-bold text-emerald-600">{{ $platform->label() }}</p>
                </div>

                @if ($classShortName)
                    <div class="pt-3 border-t border-gray-100">
                        <p class="text-xs text-gray-400 font-medium mb-1">Provider</p>
                        <p class="text-sm font-mono text-gray-700">{{ $classShortName }}</p>
                        <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $className }}</p>
                    </div>
                @endif

                @if ($result)
                    <div class="pt-3 border-t border-gray-100">
                        <p class="text-xs text-gray-400 font-medium mb-1.5">Result</p>
                        <pre class="text-xs font-mono bg-gray-50 rounded-lg p-3 text-gray-700 overflow-x-auto">@json($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)</pre>
                    </div>
                @endif

                @if ($error)
                    <div class="pt-3 border-t border-gray-100">
                        <p class="text-xs text-gray-400 font-medium mb-1.5">Error</p>
                        <div class="text-sm text-red-600 bg-red-50 rounded-lg p-3 font-medium">{{ $error }}</div>
                    </div>
                @endif
            </div>
        @endif

        <div class="mt-8">
            <p class="text-xs font-semibold text-gray-500 mb-2">Các platform hỗ trợ</p>
            <div class="grid grid-cols-2 gap-1.5">
                @foreach (array_keys(['shopee' => 'Shopee', 'lazada' => 'Lazada', 'tiktok' => 'TikTok', 'longchau' => 'Long Châu', 'pharmacity' => 'Pharmacity', 'traveloka' => 'Traveloka', 'agoda' => 'Agoda', 'booking' => 'Booking']) as $domain)
                    <div class="text-xs text-gray-500 bg-white rounded-lg px-2.5 py-1.5 border border-gray-100 font-mono">
                        {{ $domain }}.vn / {{ $domain }}.com
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</body>
</html>
