<?php
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$c = app(App\Services\AddLiveTag\ConversionsClient::class);
$f = now()->subDays(2)->toDateString();
$t = now()->toDateString();
try {
  $r = $c->fetch($f,$t,null,1,3);
  echo "pages:".($r["pages_fetched"]??0).PHP_EOL;
  echo "count:".count($r["items"]??[]).PHP_EOL;
  if(!empty($r["items"])){
    $it = $r["items"][0];
    if(is_array($it)){
      echo "keys:".implode(",",array_keys($it)).PHP_EOL;
      $safe=[]; foreach(["order_id","orderId","item_id","itemId","purchase_time","purchaseTime","shop_name","shopName","platform","source","channel","sub_source","order_source"] as $k){ if(array_key_exists($k,$it) && is_scalar($it[$k])) $safe[$k]=$it[$k]; }
      print_r($safe);
    }
  }
  echo "http:".($r["http_status"]??"-")." biz:".($r["business_status"]??"-").PHP_EOL;
} catch(Throwable $e){ echo get_class($e).":".substr($e->getMessage(),0,60); }

