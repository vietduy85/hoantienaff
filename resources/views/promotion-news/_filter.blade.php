@php
    $sources = \App\Services\PromotionNews\PromotionNewsSources::order();
    $seen = array_flip($sources);

    foreach (array_keys($sourceCounts) as $source) {
        if (! isset($seen[$source])) {
            $sources[] = $source;
            $seen[$source] = true;
        }
    }
@endphp

@if ($total > 0)
    <nav class="mt-6 flex flex-wrap gap-2" aria-label="Lọc theo nhà bán lẻ">
        <a
            href="{{ route('promotion-news.index') }}"
            @class([
                'px-3 py-1.5 rounded-full text-sm font-semibold border whitespace-nowrap transition-colors',
                'bg-emerald-600 text-white border-emerald-600 shadow-sm' => $activeSource === null,
                'bg-white text-gray-700 border-gray-300 hover:border-gray-400 hover:text-gray-900' => $activeSource !== null,
            ])
            @if ($activeSource === null) aria-current="page" @endif
        >Tất cả ({{ $total }})</a>

        @foreach ($sources as $source)
            @if (($sourceCounts[$source] ?? 0) <= 0)
                @continue
            @endif

            @php
                $isActive = $activeSource === $source;
                $label = \App\Services\PromotionNews\PromotionNewsSources::label($source);
            @endphp

            <a
                href="{{ route('promotion-news.source', ['source' => $source]) }}"
                @class([
                    'px-3 py-1.5 rounded-full text-sm font-semibold border whitespace-nowrap transition-colors',
                    'bg-emerald-600 text-white border-emerald-600 shadow-sm' => $isActive,
                    'bg-white text-gray-700 border-gray-300 hover:border-gray-400 hover:text-gray-900' => ! $isActive,
                ])
                @if ($isActive) aria-current="page" @endif
            >{{ $label }} ({{ $sourceCounts[$source] }})</a>
        @endforeach
    </nav>
@endif