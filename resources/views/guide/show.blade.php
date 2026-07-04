<x-app-layout>
    <div class="bg-gradient-to-b from-sky-50 to-white min-h-screen">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-14">

            {{-- Back --}}
            <a href="{{ route('guide.index') }}"
                class="inline-flex items-center text-sm text-sky-600 hover:text-sky-700 transition-colors">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                Quay lại Trung tâm hướng dẫn
            </a>

            {{-- Header --}}
            <div class="mt-6">
                <div class="flex items-center gap-3">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-sky-100 to-blue-100 flex items-center justify-center text-3xl shadow-sm">
                        {{ $guide['icon'] }}
                    </div>
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-900 leading-tight">
                            {{ $guide['title'] }}
                        </h1>
                        <div class="mt-1 flex items-center gap-3 text-sm text-gray-500">
                            @if ($guide['read_time'])
                            <span class="flex items-center">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                {{ $guide['read_time'] }} phút đọc
                            </span>
                            @endif
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
                    </div>
                </div>
            </div>

            {{-- Content --}}
            <div class="mt-8 bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-10">
                <article class="prose prose-sky max-w-none prose-headings:text-gray-900 prose-headings:font-bold prose-a:text-sky-600 prose-img:rounded-xl prose-pre:bg-gray-900 prose-pre:text-gray-100 prose-code:text-sky-600 prose-code:bg-sky-50 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-sm prose-strong:text-gray-900 prose-ul:space-y-1">
                    {!! $content !!}
                </article>
            </div>

            {{-- Share --}}
            <div class="mt-8 text-center">
                <p class="text-sm text-gray-400">Bài viết có hữu ích không? Hãy chia sẻ cho bạn bè nhé!</p>
                <a href="{{ route('guide.index') }}"
                    class="mt-3 inline-flex items-center px-5 py-2.5 bg-sky-600 text-white text-sm font-medium rounded-xl hover:bg-sky-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Xem thêm hướng dẫn
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
