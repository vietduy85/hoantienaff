<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>So sánh giá siêu thị - Hoàn Tiền Aff</title>
    <meta name="description" content="So sánh giá sản phẩm tại các siêu thị và cửa hàng điện máy. Tìm giá tốt nhất cho mua sắm hàng ngày.">
    <meta name="robots" content="index, follow">

    @include('components.pwa-meta')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=be-vietnam-pro:400,500,600,700,800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] { display: none !important; }
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        {{-- Marketplace: 3 cards luôn cùng 1 hàng trên mobile --}}
        .pc-marketplace {
            display: flex;
            gap: 8px;
            width: 100%;
        }
        .pc-marketplace-card {
            flex: 1 1 0;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 8px;
            background: #ffffff;
            border: 1px solid #f3f4f6;
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            opacity: 0.6;
            cursor: not-allowed;
            user-select: none;
            -webkit-user-select: none;
        }
        .pc-marketplace-icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .pc-marketplace-icon span { font-size: 18px; line-height: 1; }
        .pc-marketplace-icon.pc-shopee { background: #ffedd5; }
        .pc-marketplace-icon.pc-lazada { background: #dbeafe; }
        .pc-marketplace-icon.pc-tiktok { background: #111827; }
        .pc-marketplace-icon.pc-tiktok span { color: #ffffff; }
        .pc-marketplace-name {
            width: 100%;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            line-height: 1.2;
            text-align: center;
        }
        .pc-marketplace-status {
            width: 100%;
            font-size: 11px;
            color: #9ca3af;
            line-height: 1.15;
            text-align: center;
        }

        {{-- Product card: ảnh 84px trái, thông tin phải, footer dưới --}}
        .pc-product-card {
            display: block;
            background: #ffffff;
            border: 1px solid #f3f4f6;
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            text-decoration: none;
        }
        .pc-product-main {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
        }
        .pc-product-image {
            width: 84px;
            height: 84px;
            flex: 0 0 84px;
            background: #f9fafb;
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pc-product-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 4px;
        }
        .pc-no-image {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #d1d5db;
        }
        .pc-no-image svg { width: 32px; height: 32px; }
        .pc-product-info {
            flex: 1 1 auto;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .pc-source-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .pc-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        .pc-badge-coop { background: #d1fae5; color: #047857; }
        .pc-badge-neut { background: #f3f4f6; color: #4b5563; }
        .pc-brand {
            font-size: 11px;
            color: #9ca3af;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pc-name {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .pc-meta {
            font-size: 11px;
            color: #9ca3af;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pc-price-row {
            display: flex;
            align-items: baseline;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 2px;
        }
        .pc-price { font-size: 20px; font-weight: 700; color: #dc2626; line-height: 1; }
        .pc-price .pc-currency { font-size: 14px; font-weight: 500; margin-left: 1px; }
        .pc-old-price {
            font-size: 12px;
            color: #9ca3af;
            text-decoration: line-through;
            line-height: 1;
        }
        .pc-discount {
            font-size: 11px;
            font-weight: 600;
            background: #fee2e2;
            color: #dc2626;
            padding: 2px 6px;
            border-radius: 9999px;
            line-height: 1.4;
        }
        .pc-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-top: 1px solid #f9fafb;
        }
        .pc-footer-link { font-size: 12px; color: #059669; font-weight: 600; }
        .pc-stock { font-size: 11px; color: #9ca3af; }
        .pc-stock-empty { font-size: 11px; color: #f87171; }

        @media (min-width: 768px) {
            .pc-product-image {
                width: 96px;
                height: 96px;
                flex-basis: 96px;
            }
        }
    </style>
</head>
<body class="bg-gray-50 font-sans text-gray-800 antialiased min-h-screen">

    {{-- HEADER --}}
    <header class="sticky top-0 z-50 bg-emerald-600 shadow-lg">
        <div class="max-w-2xl mx-auto px-4 h-14 flex items-center gap-3">
            <a href="{{ url('/') }}"
               class="shrink-0 w-9 h-9 flex items-center justify-center rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors"
               aria-label="Trang chủ">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <h1 class="flex-1 text-white font-semibold text-lg text-center truncate">So sánh giá siêu thị</h1>
            <div class="w-9 shrink-0"></div>
        </div>
    </header>

    {{-- SEARCH BOX --}}
    <div class="bg-emerald-600 pb-6 pt-2">
        <form action="{{ route('price-comparison.index') }}" method="GET" class="max-w-2xl mx-auto px-4 flex gap-2">
            <div class="relative flex-1 min-w-0">
                <input type="text"
                       name="keyword"
                       value="{{ $keyword }}"
                       placeholder="Tìm sản phẩm, VD: Mì Hảo Hảo"
                       class="w-full h-14 pl-5 {{ $keyword ? 'pr-12' : 'pr-5' }} rounded-2xl border-2 border-emerald-200 focus:border-white focus:ring-2 focus:ring-white/30 bg-white text-base text-gray-800 placeholder:text-gray-400 outline-none transition-colors shadow-sm"
                       aria-label="Tìm kiếm sản phẩm"
                       autofocus>

                @if($keyword)
                    <a href="{{ route('price-comparison.index') }}"
                       class="absolute right-3 top-1/2 -translate-y-1/2 w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-500 transition-colors"
                       aria-label="Xoá tìm kiếm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </a>
                @endif
            </div>

            <button type="submit"
                    class="shrink-0 w-12 sm:w-14 h-14 flex items-center justify-center rounded-2xl bg-white border-2 border-emerald-200 hover:border-emerald-400 text-emerald-700 transition-colors shadow-sm"
                    aria-label="Tìm kiếm">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </button>
        </form>
    </div>

    <main class="max-w-2xl mx-auto px-4 pb-20 space-y-4 -mt-1">

        {{-- KHÔNG CÓ TỪ KHOÁ: Hiển thị giới thiệu --}}
        @if(!$keyword)
            <div class="text-center pt-12 pb-8 px-4 space-y-3">
                <div class="text-5xl mb-4">🔍</div>
                <h2 class="text-xl sm:text-2xl font-bold text-gray-800">Khám phá giá tốt hơn</h2>
                <p class="text-sm text-gray-500 leading-relaxed max-w-xs mx-auto">
                    So sánh giá sản phẩm tại các siêu thị và cửa hàng điện máy để mua sắm tiết kiệm hơn.
                </p>
            </div>
        @endif

        {{-- LỖI TỪ SERVER --}}
        @if($error)
            <div class="bg-red-50 border border-red-200 rounded-2xl p-5 text-center space-y-2 mt-4">
                <div class="text-3xl">⚠️</div>
                <p class="text-sm text-red-700 font-medium">{{ $error }}</p>
            </div>
        @endif

        {{-- KHU VỰC SÀN TMĐT --}}
        @if($keyword && !$error)
            @php
                $shopeeLink = $marketplaceLinks['shopee'] ?? null;
                $lazadaLink = $marketplaceLinks['lazada'] ?? null;
                $tiktokLink = $marketplaceLinks['tiktok'] ?? null;
            @endphp
            <section class="space-y-3 pt-2">
                <p class="text-sm font-semibold text-gray-700">Mua "{{ $keyword }}" trên sàn</p>
                <div class="pc-marketplace">
                    @if($shopeeLink)
                        @php
                            $shopeePending = empty($shopeeLink['affiliate_url'])
                                && in_array($shopeeLink['status'] ?? null, ['pending', 'processing'], true)
                                && (int) ($shopeeLink['request_id'] ?? 0);
                        @endphp

                        @if($shopeePending)
                            <div class="contents"
                                 x-data="pcShopeeCard(@js([
                                     'jobId'        => (int) $shopeeLink['request_id'],
                                     'searchUrl'    => $shopeeLink['search_url'],
                                     'affiliateUrl' => null,
                                 ]))">
                                <template x-if="pending">
                                    <div class="pc-marketplace-card">
                                        <div class="pc-marketplace-icon pc-shopee"><span>🛒</span></div>
                                        <span class="pc-marketplace-name">Shopee</span>
                                        <span class="pc-marketplace-status">
                                            <span x-text="statusText">Đang tạo link...</span>
                                        </span>
                                    </div>
                                </template>
                                <template x-if="!pending">
                                    <a x-bind:href="affiliateUrl || searchUrl"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="pc-marketplace-card"
                                       style="opacity:1;cursor:pointer;text-decoration:none;">
                                        <div class="pc-marketplace-icon pc-shopee"><span>🛒</span></div>
                                        <span class="pc-marketplace-name">Shopee</span>
                                        <span class="pc-marketplace-status">
                                            <span x-text="affiliateUrl ? 'Mua sắm ↗' : 'Xem trên Shopee ↗'"></span>
                                        </span>
                                    </a>
                                </template>
                            </div>
                        @else
                            <a href="{{ $shopeeLink['affiliate_url'] ?? $shopeeLink['search_url'] }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="pc-marketplace-card"
                               style="opacity:1;cursor:pointer;text-decoration:none;">
                                <div class="pc-marketplace-icon pc-shopee"><span>🛒</span></div>
                                <span class="pc-marketplace-name">Shopee</span>
                                <span class="pc-marketplace-status">
                                    @if($shopeeLink['affiliate_url'])
                                        Mua sắm ↗
                                    @else
                                        Xem trên Shopee ↗
                                    @endif
                                </span>
                            </a>
                        @endif
                    @else
                        <div class="pc-marketplace-card">
                            <div class="pc-marketplace-icon pc-shopee"><span>🛒</span></div>
                            <span class="pc-marketplace-name">Shopee</span>
                            <span class="pc-marketplace-status">Đang cập nhật</span>
                        </div>
                    @endif

                    @if($lazadaLink)
                        <a href="{{ $lazadaLink['affiliate_url'] ?? $lazadaLink['search_url'] }}"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="pc-marketplace-card"
                           style="opacity:1;cursor:pointer;text-decoration:none;">
                            <div class="pc-marketplace-icon pc-lazada"><span>🏬</span></div>
                            <span class="pc-marketplace-name">Lazada</span>
                            <span class="pc-marketplace-status">
                                @if($lazadaLink['affiliate_url'])
                                    Mua sắm ↗
                                @else
                                    Xem trên Lazada ↗
                                @endif
                            </span>
                        </a>
                    @else
                        <div class="pc-marketplace-card">
                            <div class="pc-marketplace-icon pc-lazada"><span>🏬</span></div>
                            <span class="pc-marketplace-name">Lazada</span>
                            <span class="pc-marketplace-status">Đang cập nhật</span>
                        </div>
                    @endif

                    @if($tiktokLink)
                        <a href="{{ $tiktokLink['affiliate_url'] ?? $tiktokLink['search_url'] }}"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="pc-marketplace-card"
                           style="opacity:1;cursor:pointer;text-decoration:none;">
                            <div class="pc-marketplace-icon pc-tiktok"><span>🎵</span></div>
                            <span class="pc-marketplace-name">TikTok Shop</span>
                            <span class="pc-marketplace-status">
                                @if($tiktokLink['affiliate_url'])
                                    Mua sắm ↗
                                @else
                                    Xem trên TikTok Shop ↗
                                @endif
                            </span>
                        </a>
                    @else
                        <div class="pc-marketplace-card">
                            <div class="pc-marketplace-icon pc-tiktok"><span>🎵</span></div>
                            <span class="pc-marketplace-name">TikTok Shop</span>
                            <span class="pc-marketplace-status">Đang cập nhật</span>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        {{-- TABS NHÀ BÁN LẺ --}}
        @if($keyword && !$error)
            <section class="space-y-3 pt-1">
                <div class="flex overflow-x-auto gap-2 pb-2 scrollbar-hide -mx-4 px-4">
                    @php
                        $activeTab = $retailer ?? 'all';
                        $supportedTabs = ['coop', 'bhx', 'kingfoodmart'];
                        $retailerCount = fn (string $key) => isset($retailerCounts[$key]) && $retailerCounts[$key] !== null
                            ? ' ('.number_format($retailerCounts[$key]).')'
                            : '';
                    @endphp
                    {{-- Tất cả --}}
                    <a href="{{ route('price-comparison.index', array_filter(['keyword' => $keyword, 'sort' => $sort])) }}"
                       class="shrink-0 px-4 py-2 rounded-full text-sm font-medium border transition-colors
                              {{ $activeTab === 'all' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-600 border-gray-200 hover:border-emerald-300' }}">
                        Tất cả{{ $retailerCount('all') }}
                    </a>
                    {{-- Co.op --}}
                    <a href="{{ route('price-comparison.index', array_filter(['keyword' => $keyword, 'retailer' => 'coop', 'sort' => $sort])) }}"
                       class="shrink-0 px-4 py-2 rounded-full text-sm font-medium border transition-colors
                              {{ $activeTab === 'coop' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-600 border-gray-200 hover:border-emerald-300' }}">
                        Co.op{{ $retailerCount('coop') }}
                    </a>
                    {{-- Các retailer khác --}}
                    @foreach($retailers as $key => $name)
                        @if($key === 'coop') @continue @endif
                        @if(in_array($key, $supportedTabs, true))
                            <a href="{{ route('price-comparison.index', array_filter(['keyword' => $keyword, 'retailer' => $key, 'sort' => $sort])) }}"
                               class="shrink-0 px-4 py-2 rounded-full text-sm font-medium border transition-colors
                                      {{ $activeTab === $key ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-600 border-gray-200 hover:border-emerald-300' }}">
                                {{ $name }}{{ $retailerCount($key) }}
                            </a>
                        @else
                            <button type="button"
                                    disabled
                                    class="shrink-0 px-4 py-2 rounded-full text-sm font-medium border bg-gray-50 text-gray-400 border-gray-100 cursor-not-allowed"
                                    title="{{ $name }} — Đang cập nhật">
                                {{ $name }}
                            </button>
                        @endif
                    @endforeach
                </div>

                {{-- SORT --}}
                <div class="flex gap-2">
                    <a href="{{ route('price-comparison.index', array_filter(['keyword' => $keyword, 'retailer' => $retailer, 'sort' => 'relevance'])) }}"
                       class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                              {{ $sort === 'relevance' ? 'bg-emerald-50 text-emerald-700 border-emerald-200 font-semibold' : 'bg-white text-gray-600 border-gray-200 hover:border-emerald-200' }}">
                        Liên quan
                    </a>
                    <a href="{{ route('price-comparison.index', array_filter(['keyword' => $keyword, 'retailer' => $retailer, 'sort' => 'price_asc'])) }}"
                       class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                              {{ $sort === 'price_asc' ? 'bg-emerald-50 text-emerald-700 border-emerald-200 font-semibold' : 'bg-white text-gray-600 border-gray-200 hover:border-emerald-200' }}">
                        Giá thấp
                    </a>
                    <a href="{{ route('price-comparison.index', array_filter(['keyword' => $keyword, 'retailer' => $retailer, 'sort' => 'price_desc'])) }}"
                       class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                              {{ $sort === 'price_desc' ? 'bg-emerald-50 text-emerald-700 border-emerald-200 font-semibold' : 'bg-white text-gray-600 border-gray-200 hover:border-emerald-200' }}">
                        Giá cao
                    </a>
                </div>
            </section>
        @endif

        {{-- TÊN RETAILER CHƯA HỖ TRỢ --}}
        @if($keyword && !$providerAvailable && !$error)
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 text-center space-y-2">
                <div class="text-3xl">🔧</div>
                <p class="text-sm text-amber-800 font-medium">{{ $retailerNotice ?? 'Nguồn giá này đang được cập nhật.' }}</p>
                <a href="{{ route('price-comparison.index', array_filter(['keyword' => $keyword, 'sort' => $sort])) }}"
                   class="inline-flex items-center text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                    Xem tất cả siêu thị →
                </a>
            </div>
        @endif

        {{-- KẾT QUẢ TÌM KIẾM --}}
        @if($keyword && $providerAvailable && !$error)
            <p class="text-sm text-gray-500 font-medium pt-1">
                Kết quả cho "<span class="text-gray-700 font-semibold">{{ $keyword }}</span>"
                — {{ number_format($pagination['total']) }} sản phẩm
                @if(($activeRetailer ?? 'all') === 'all' && $sourceCount > 0)
                    từ {{ $sourceCount }} siêu thị
                @endif
            </p>
        @endif

        {{-- DANH SÁCH SẢN PHẨM --}}
        @if($keyword && $providerAvailable && !$error && count($products) > 0)
            <section class="space-y-3">
                @foreach($products as $product)
                    @php
                        $name = $product['name'] ?? 'Sản phẩm';
                        $brand = $product['brand'] ?? '';
                        $category = $product['category'] ?? '';
                        $price = $product['price'] ?? null;
                        $origPrice = $product['original_price'] ?? null;
                        $discAmount = $product['discount_amount'] ?? 0;
                        $discPercent = $product['discount_percent'] ?? 0;
                        $imageUrl = $product['image_url'] ?? null;
                        $productUrl = $product['product_url'] ?? '#';
                        $stock = $product['stock'] ?? null;
                        $sellable = $product['sellable'] ?? false;
                        $unit = $product['unit'] ?? '';
                        $source = $product['source'] ?? 'coop';
                        $sourceBadges = [
                            'coop'         => 'Co.op',
                            'coop_online'  => 'Co.op',
                            'bhx'          => 'BHX',
                            'bach_hoa_xanh' => 'BHX',
                            'kingfoodmart' => 'Kingfoodmart',
                            'winmart'      => 'WinMart',
                            'dmx'          => 'Điện Máy Xanh',
                            'cps'          => 'CellphoneS',
                            'nk'           => 'Nguyễn Kim',
                            'lotte'        => 'LOTTE Mart',
                        ];
                        $badgeLabel = $sourceBadges[$source] ?? ucfirst(str_replace('_', ' ', $source));
                        $isCoop = in_array($source, ['coop', 'coop_online'], true);
                    @endphp
                    <a href="{{ $productUrl }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="pc-product-card">
                        <div class="pc-product-main">
                            <div class="pc-product-image">
                                @if($imageUrl)
                                    <img src="{{ $imageUrl }}"
                                         alt="{{ $name }}"
                                         loading="lazy"
                                         onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 fill=%22%23f9fafb%22/%3E%3Ctext x=%2250%22 y=%2255%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2211%22%3EKhông có ảnh%3C/text%3E%3C/svg%3E'">
                                @else
                                    <div class="pc-no-image">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                @endif
                            </div>

                            <div class="pc-product-info">
                                <div class="pc-source-row">
                                    <span class="pc-badge {{ $isCoop ? 'pc-badge-coop' : 'pc-badge-neut' }}">{{ $badgeLabel }}</span>
                                    @if($brand)
                                        <span class="pc-brand">{{ $brand }}</span>
                                    @endif
                                </div>

                                <h3 class="pc-name">{{ $name }}</h3>

                                @if($unit || $category)
                                    <p class="pc-meta">
                                        @if($unit){{ $unit }}@endif
                                        @if($unit && $category) · @endif
                                        @if($category){{ $category }}@endif
                                    </p>
                                @endif

                                <div class="pc-price-row">
                                    @if($price !== null)
                                        <span class="pc-price">{{ number_format((float)$price, 0, ',', '.') }}<span class="pc-currency">đ</span></span>
                                    @else
                                        <span class="pc-meta">Liên hệ</span>
                                    @endif

                                    @if($origPrice !== null && $origPrice != $price)
                                        <span class="pc-old-price">{{ number_format((float)$origPrice, 0, ',', '.') }}đ</span>
                                    @endif

                                    @if($discPercent > 0)
                                        <span class="pc-discount">-{{ (int)$discPercent }}%</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="pc-footer">
                            <span class="pc-footer-link">Xem sản phẩm ↗</span>
                            @if($stock !== null)
                                <span class="{{ $stock > 0 ? 'pc-stock' : 'pc-stock-empty' }}">
                                    @if($stock > 0) Còn {{ number_format($stock) }} @else Hết hàng @endif
                                </span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </section>

            {{-- XEM THÊM --}}
            @if($page < $pagination['total_pages'])
                <div class="pt-2">
                    <a href="{{ route('price-comparison.index', array_filter(['keyword' => $keyword, 'retailer' => $retailer, 'sort' => $sort, 'page' => $page + 1])) }}"
                       class="block w-full text-center py-3.5 bg-white border border-gray-200 rounded-2xl text-emerald-600 font-semibold text-sm hover:border-emerald-300 hover:bg-emerald-50 transition-colors">
                        Xem thêm kết quả
                    </a>
                </div>
            @endif
        @endif

        {{-- KHÔNG CÓ KẾT QUẢ --}}
        @if($keyword && $providerAvailable && !$error && count($products) === 0)
            <div class="text-center pt-12 pb-8 px-4 space-y-2">
                <div class="text-4xl">📭</div>
                <p class="text-sm text-gray-500 font-medium">Không tìm thấy sản phẩm phù hợp.</p>
                <p class="text-xs text-gray-400">Thử tìm kiếm với từ khoá khác.</p>
            </div>
        @endif

    </main>

    {{-- FOOTER --}}
    <footer class="bg-white border-t border-gray-100 py-4 text-center text-xs text-gray-400 pb-safe">
        Hoàn Tiền Aff © {{ date('Y') }}
    </footer>

{{-- A.3: Shopee Extension — tạo/poll link ngắn cho tài khoản đã đăng nhập --}}
    <script>
        window.pcShopeeCard = function (config) {
            return {
                jobId: config.jobId,
                searchUrl: config.searchUrl,
                affiliateUrl: config.affiliateUrl || null,
                pending: true,
                statusText: 'Đang tạo link...',
                _startAt: 0,
                _live: false,

                init() {
                    this._startAt = Date.now();
                    this._live = true;
                    this.poll();
                },

                poll() {
                    if (!this._live) return;

                    if (Date.now() - this._startAt > 30000) {
                        this.finishFallback();
                        return;
                    }

                    fetch('/api/link-request/' + this.jobId, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    })
                    .then((r) => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                    .then((data) => {
                        if (data.id !== this.jobId) {
                            this.finishFallback();
                            return;
                        }
                        if (data.status === 'completed' && data.affiliate_url) {
                            this.affiliateUrl = data.affiliate_url;
                            this.pending = false;
                            return;
                        }
                        if (data.status === 'failed' || data.status === 'rejected') {
                            this.finishFallback();
                            return;
                        }
                        window.setTimeout(() => this.poll(), 1500);
                    })
                    .catch(() => {
                        window.setTimeout(() => this.poll(), 3000);
                    });
                },

                finishFallback() {
                    this._live = false;
                    this.pending = false;
                    this.affiliateUrl = null;
                },
            };
        };
    </script>

</body>
</html>