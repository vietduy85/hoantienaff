<x-app-layout>
    <div class="min-h-screen bg-gradient-to-b from-emerald-50 to-white">
        <div class="max-w-lg mx-auto px-4 py-6 space-y-5">

            @include('dashboard.partials.greeting-card')

            @include('dashboard.partials.link-generator')

            @if ($pinnedLinks->isNotEmpty())
                @include('dashboard.partials.pinned-links', [
                    'links' => $pinnedLinks
                ])
            @endif

            @if ($recentLinks->isNotEmpty())
                @include('dashboard.partials.recent-links', [
                    'links' => $recentLinks
                ])
            @endif

        </div>
    </div>
</x-app-layout>