<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Processing jobs with affiliate_url filled ===\n";
$filled = \App\Models\LinkRequest::where('status', 'processing')
    ->whereNotNull('affiliate_url')
    ->where('affiliate_url', '!=', '')
    ->count();
echo "Count: $filled\n";

echo "\n=== Oldest 5 processing jobs ===\n";
$oldest = \App\Models\LinkRequest::where('status', 'processing')
    ->orderBy('id')->limit(5)->get();
foreach ($oldest as $lr) {
    echo "id={$lr->id} created={$lr->created_at} updated={$lr->updated_at} affiliate_url=" . ($lr->affiliate_url ? substr($lr->affiliate_url, 0, 40) : 'EMPTY') . " user={$lr->user_id}\n";
}

echo "\n=== Newest 5 processing jobs ===\n";
$newest = \App\Models\LinkRequest::where('status', 'processing')
    ->orderBy('id', 'desc')->limit(5)->get();
foreach ($newest as $lr) {
    echo "id={$lr->id} created={$lr->created_at} updated={$lr->updated_at} affiliate_url=" . ($lr->affiliate_url ? substr($lr->affiliate_url, 0, 40) : 'EMPTY') . " user={$lr->user_id}\n";
}

echo "\n=== Checking if POST /results ever reached: look at updated_at vs created_at for stuck jobs ===\n";
$sample = \App\Models\LinkRequest::where('status', 'processing')
    ->orderBy('id')->get();
$sameCount = 0;
$diffCount = 0;
foreach ($sample as $lr) {
    if ($lr->created_at == $lr->updated_at) {
        $sameCount++;
    } else {
        $diffCount++;
    }
}
echo "created_at == updated_at (no POST): $sameCount\n";
echo "created_at != updated_at (POST may have touched it): $diffCount\n";

echo "\n=== All 58 processing: count by user_id ===\n";
$byUser = \App\Models\LinkRequest::where('status', 'processing')
    ->groupBy('user_id')
    ->selectRaw('user_id, count(*) as cnt')
    ->pluck('cnt', 'user_id');
foreach ($byUser as $uid => $cnt) {
    echo "user_id=$uid: $cnt jobs\n";
}
