<?php
require "vendor/autoload.php";
$app=require"bootstrap/app.php";
$k=$app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use App\Services\ShopeeFashionAlertService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$s = app(ShopeeFashionAlertService::class);
// pick a user with unseen orders >=25th
$ord = DB::table("affiliate_order_items")
    ->where("platform","Shopee")
    ->whereRaw("ABS(shopee_commission_rate-7)<0.0001")
    ->where("ordered_at",">=","2026-10-25 00:00:00")
    ->whereNotNull("user_id")
    ->whereNotIn("order_id", DB::table("user_shopee_fashion_seen_orders")->pluck("order_id"))
    ->first();
if(!$ord){ echo "no_unseen_ge25\n"; exit; }
$u = User::find($ord->user_id);
if(!$u){ echo "no_user\n"; exit; }
echo "user:".$u->id." unseen:".$s->unseenCount($u)."\n";
$ids = $s->unseenOrderIds($u);
echo "ids:".implode(",",$ids)."\n";
// check an old order exists in DB but not returned
$old = DB::table("affiliate_order_items")
    ->where("platform","Shopee")
    ->whereRaw("ABS(shopee_commission_rate-7)<0.0001")
    ->where("ordered_at","<","2026-10-25 00:00:00")
    ->where("user_id",$u->id)
    ->first();
if($old){ echo "has_old:".$old->order_id."\n"; } else { echo "no_old_for_user\n"; }

