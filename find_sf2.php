<?php
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$rows = DB::table("affiliate_order_items")->where("platform","ShopeeFood")->select("order_id","checkout_id","item_id","ordered_at","affiliate_status")->limit(10)->get();
foreach($rows as $r){ print_r((array)$r); echo "---\n"; }

