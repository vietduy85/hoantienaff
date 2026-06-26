<x-app-layout>
    <div class="min-h-screen bg-gradient-to-b from-emerald-50 to-white">
        <div class="max-w-lg mx-auto max-[390px]:px-3 px-4 max-[390px]:py-3 py-4 max-[390px]:space-y-2.5 space-y-3">

            @include('dashboard.partials.greeting-card')

            @include('dashboard.partials.link-generator')

            @include('dashboard.partials.pinned-links', [
                'links' => $pinnedLinks
            ])

            @include('dashboard.partials.recent-links', [
                'links' => $recentLinks
            ])

        </div>
    </div>
</x-app-layout>
