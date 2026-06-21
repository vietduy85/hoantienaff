<x-app-layout>
    <div class="min-h-screen bg-gradient-to-b from-emerald-50 to-white">
        <div class="max-w-lg mx-auto px-4 py-6 space-y-5">

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
