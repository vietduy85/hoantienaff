<?php
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$c = app(App\Services\AddLiveTag\ConversionsClient::class);
$f = now()->subDays(2)->toDateString();
$t = now()->toDateString();
try {
  $r = $c->fetch($f,$t,null,1,10);
  $items = $r["items"]??[];
  $keysAll = [];
  foreach($items as $it){
    if(is_array($it)){ foreach($it as $k=>$v){ $keysAll[$k]=true; } }
  }
  echo "uniq:".implode(",",array_keys($keysAll)).PHP_EOL;
  // look for any hint
  $found=[];
  foreach($items as $it){
    if(!is_array($it)) continue;
    foreach(["platform","source","channel","type","order_source","shop_type","category","campaign_type"] as $k){
      if(array_key_exists($k,$it) && is_scalar($it[$k])){ $found[$k]=isset($found[$k])?($found[$k]."|".$it[$k]):$it[$k]; }
    }
  }
  if(empty($found)) echo "no_platform_field"; else print_r($found);
} catch(Throwable $e){ echo get_class($e); }

