<?php
require 'vendor/autoload.php';
\ = require_once 'bootstrap/app.php';
\ = \->make(Illuminate\Contracts\Console\Kernel::class);
\->bootstrap();
use Illuminate\Support\Facades\DB;
\ = DB::table('affiliate_order_items')->where('platform','Shopee')->whereRaw('ABS(shopee_commission_rate-7.0)<0.001')->limit(5)->get();
foreach(\ as \){ echo \->order_id.'|'.\->item_id.'|'.\->user_id.'|'.\->shopee_commission_rate.PHP_EOL; }

