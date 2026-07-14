<header class="bg-white border-b border-gray-100 sticky top-0 z-50">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14 sm:h-16">
            {{-- Logo --}}
            <a href="/" class="flex items-center gap-2 shrink-0">
                <span class="text-xl sm:text-2xl">💰</span>
                <span class="font-bold text-emerald-600 text-base sm:text-lg">Hoàn Tiền Aff</span>
            </a>

            {{-- Desktop Nav --}}
            <nav class="hidden md:flex items-center gap-6">
                <a href="{{ route('page.how_it_works') }}" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors">Cách hoạt động</a>
                <a href="{{ route('page.about') }}" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors">Giới thiệu</a>
                <a href="{{ route('page.faq') }}" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors">FAQ</a>
                <a href="{{ route('page.contact') }}" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors">Liên hệ</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-semibold rounded-lg transition-colors">
                        Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}" class="inline-flex items-center px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-semibold rounded-lg transition-colors">
                        Đăng nhập
                    </a>
                @endauth
            </nav>

            {{-- Mobile Menu --}}
            <div x-data="{ open: false }" class="md:hidden">
                <button @click="open = !open" class="p-2 text-gray-500 hover:text-gray-700" aria-label="Menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path x-show="!open" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        <path x-show="open" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
                <div x-show="open" @click.away="open = false" x-cloak
                     class="absolute right-0 top-14 w-56 bg-white border border-gray-100 rounded-xl shadow-lg py-2 z-50">
                    <a href="{{ route('page.how_it_works') }}" class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">Cách hoạt động</a>
                    <a href="{{ route('page.about') }}" class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">Giới thiệu</a>
                    <a href="{{ route('page.faq') }}" class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">FAQ</a>
                    <a href="{{ route('page.contact') }}" class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">Liên hệ</a>
                    <hr class="my-1 border-gray-100">
                    @auth
                        <a href="{{ route('dashboard') }}" class="block px-4 py-2.5 text-sm font-semibold text-emerald-600 hover:bg-gray-50">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="block px-4 py-2.5 text-sm font-semibold text-emerald-600 hover:bg-gray-50">Đăng nhập</a>
                    @endauth
                </div>
            </div>
        </div>
    </div>
</header>
