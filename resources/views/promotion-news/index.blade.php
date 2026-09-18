<x-layouts.public :pageTitle="$pageTitle" :pageDescription="$pageDescription" :canonical="$canonical">
    <section class="py-10 sm:py-14">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Tin tức khuyến mãi</h1>
            <p class="mt-1 text-gray-500">Tổng hợp ưu đãi mới nhất từ các siêu thị và thương hiệu.</p>

            @include('promotion-news._filter')

            @if ($news->isEmpty())
                <div class="mt-10 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center text-gray-500">
                    Chưa có tin khuyến mãi nào.
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
