<?php
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$c = app(App\Services\AddLiveTag\ConversionsClient::class);
$f = now()->subDays(7)->toDateString();
$t = now()->toDateString();
try {
  $r = $c->fetch($f,$t,null,1,50);
  $items = $r["items"]??[];
  foreach($items as $it){
    if(!is_array($it)) continue;
    $json = strtolower(json_encode($it));
    if(strpos($json,"shopeefood")!==false || strpos($json,"now-food")!==false || strpos($json,"food")!==false){
      // print safe
      $safe=[];
      foreach(["item_name","shop_id","order_sn","checkout_id"] as $k){ if(isset($it[$k]) && is_scalar($it[$k])) $safe[$k]=substr((string)$it[$k],0,40); }
      print_r($safe);
      break;
    }
  }
} catch(Throwable $e){}

