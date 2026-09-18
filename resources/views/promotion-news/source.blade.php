<x-layouts.public :pageTitle="$pageTitle" :pageDescription="$pageDescription" :canonical="$canonical">
    <section class="py-10 sm:py-14">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <a href="{{ route('promotion-news.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">&larr; Tất cả tin khuyến mãi</a>

            <h1 class="mt-3 flex items-center gap-2 text-2xl sm:text-3xl font-bold text-gray-900">
                <span aria-hidden="true">{{ $sourceEmoji }}</span>
                <span>Tin khuyến mãi {{ $sourceLabel }}</span>
            </h1>

            @include('promotion-news._filter')

            @if ($news->isEmpty())
                <div class="mt-10 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center text-gray-500">
                    Hiện chưa có tin khuyến mãi nào từ {{ $sourceLabel }}.
                </div>
            @else
                <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
                    @foreach ($news as $item)
                        <x-promotion-news-card
                            :item="$item"
                            :href="$item->landingUrl"
                            :external="true"
                        />
                    @endforeach
                </div>

                <div class="mt-8">
                    {{ $news->links() }}
                </div>
            @endif
        </div>
    </section>
</x-layouts.public>
