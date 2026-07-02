<x-app-layout>
    <div class="bg-gradient-to-b from-sky-50 to-white">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 sm:py-16 lg:py-20">

            {{-- Hero --}}
            <div class="text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-sky-100 shadow-sm mb-6">
                    <span class="text-3xl">📘</span>
                </div>
                <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 tracking-tight">
                    Trung tâm hướng dẫn
                </h1>
                <p class="mt-2 text-xl sm:text-2xl font-bold text-sky-600">
                    HoanTien.xyz
                </p>
                <p class="mt-4 max-w-2xl mx-auto text-base sm:text-lg text-gray-500 leading-relaxed">
                    Mọi hướng dẫn sử dụng, mẹo săn SALE, Affiliate, Hoàn tiền đều có tại đây.
                </p>
            </div>

            {{-- Search --}}
            <div class="mt-10 max-w-xl mx-auto">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <input type="text" placeholder="Tìm kiếm hướng dẫn..."
                        class="block w-full pl-12 pr-4 py-3.5 text-base bg-white border border-gray-200 rounded-xl shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent transition">
                </div>
            </div>

            {{-- Cards --}}
            <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($guides as $slug => $guide)
                <a href="{{ route('guide.show', $slug) }}"
                    class="group relative flex flex-col rounded-2xl border border-gray-100 bg-white p-5 shadow-sm hover:shadow-lg hover:border-sky-200 transition-all duration-200">

                    {{-- Icon --}}
                    <div class="flex items-center justify-between">
                        <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-sky-50 to-blue-50 flex items-center justify-center text-2xl group-hover:scale-110 transition-transform duration-200">
                            {{ $guide['icon'] }}
                        </div>
                        @if ($guide['badge'])
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                            @switch($guide['badge_color'])
                                @case('emerald') bg-emerald-100 text-emerald-700 @break
                                @case('blue') bg-blue-100 text-blue-700 @break
                                @case('red') bg-red-100 text-red-700 @break
                                @case('purple') bg-purple-100 text-purple-700 @break
                                @case('orange') bg-orange-100 text-orange-700 @break
                                @default bg-gray-100 text-gray-700
                            @endswitch
                        ">
                            {{ $guide['badge'] }}
                        </span>
                        @endif
                    </div>

                    {{-- Title --}}
                    <h3 class="mt-4 text-base font-semibold text-gray-900 group-hover:text-sky-600 transition-colors">
                        {{ $guide['title'] }}
                    </h3>

                    {{-- Description --}}
                    <p class="mt-1.5 text-sm text-gray-500 line-clamp-2 leading-relaxed">
                        {{ $guide['description'] }}
                    </p>

                    {{-- Read time --}}
                    @if ($guide['read_time'])
                    <div class="mt-auto pt-4 flex items-center text-xs text-gray-400">
                        <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ $guide['read_time'] }} phút đọc
                    </div>
                    @endif
                </a>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
