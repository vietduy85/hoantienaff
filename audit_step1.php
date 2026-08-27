<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\AffiliateOrderItem;
use App\Models\LinkRequest;
use Illuminate\Support\Facades\DB;

echo "=== STEP 1: FIND USER ===" . PHP_EOL;
$user = User::where('email', 'hangngo070787@gmail.com')->first();
if (!$user) {
    echo "USER NOT FOUND!" . PHP_EOL;
    exit;
}
echo "user_id: {$user->id}" . PHP_EOL;
echo "email: {$user->email}" . PHP_EOL;
echo "username: {$user->username}" . PHP_EOL;
echo "wallet_balance: {$user->wallet_balance}" . PHP_EOL;
echo "total_earned: {$user->total_earned}" . PHP_EOL;
echo "total_withdrawn: {$user->total_withdrawn}" . PHP_EOL;
echo "created_at: {$user->created_at}" . PHP_EOL;
echo "updated_at: {$user->updated_at}" . PHP_EOL;

echo PHP_EOL . "=== STEP 2: ALL ORDERS FOR THIS USER ===" . PHP_EOL;
$items = AffiliateOrderItem::where('user_id', $user->id)->get();
echo "affiliate_order_items count: {$items->count()}" . PHP_EOL;
foreach ($items as $item) {
    echo sprintf("  item_id=%d | order_id=%s | status=%s | cashback=%.2f | commission=%.2f | platform=%s | import_batch=%s | created=%s",
        $item->id, $item->order_id, $item->status, $item->cashback_amount ?? 0, $item->commission_amount ?? 0,
        $item->platform ?? 'null', $item->import_batch ?? 'null', $item->created_at) . PHP_EOL;
}

echo PHP_EOL . "=== STEP 3: LINK REQUESTS FOR THIS USER ===" . PHP_EOL;
$links = LinkRequest::where('user_id', $user->id)->get();
echo "link_requests count: {$links->count()}" . PHP_EOL;
foreach ($links as $link) {
    echo sprintf("  id=%d | order_id=%s | sub_id=%s | platform=%s | status=%s | created=%s",
        $link->id, $link->order_id ?? 'null', $link->sub_id ?? 'null', $link->platform ?? 'null',
        $link->status ?? 'null', $link->created_at) . PHP_EOL;
}

echo PHP_EOL . "=== STEP 4: SEARCH BY USERNAME IN ORDERS ===" . PHP_EOL;
$usernameItems = AffiliateOrderItem::where('username', $user->username)->get();
echo "Items with matching username: {$usernameItems->count()}" . PHP_EOL;

echo PHP_EOL . "=== STEP 5: UNIQUE ORDERS FOR THIS USER ===" . PHP_EOL;
$uniqueOrders = AffiliateOrderItem::where('user_id', $user->id)
    ->select('order_id', 'status', DB::raw('SUM(cashback_amount) as total_cashback'), DB::raw('COUNT(*) as items'))
    ->groupBy('order_id', 'status')
    ->get();
echo "Unique orders: {$uniqueOrders->count()}" . PHP_EOL;
foreach ($uniqueOrders as $o) {
    echo sprintf("  order_id=%s | status=%s | total_cashback=%.2f | items=%d",
        $o->order_id, $o->status, $o->total_cashback, $o->items) . PHP_EOL;
}
