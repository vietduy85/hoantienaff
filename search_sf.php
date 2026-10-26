<?php
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$c = app(App\Services\AddLiveTag\ConversionsClient::class);
// Try to find by searching recent items
$f = now()->subDays(14)->toDateString();
$t = now()->toDateString();
try {
  // fetch first page
  $r = $c->fetch($f,$t,null,1,50);
  $items = $r["items"]??[];
  $targets = ["1877444014","1879578695","1882629760"];
  foreach($items as $it){
    if(!is_array($it)) continue;
    $json = json_encode($it);
    foreach($targets as $tg){
      if(strpos($json,$tg)!==false){
        echo "FOUND:$tg\n";
        // print safe fields
        foreach(["order_sn","checkout_id","order_id","orderId"] as $k){ if(isset($it[$k]) && is_scalar($it[$k])) echo " $k:".$it[$k]; }
        echo "\n";
        break 2;
      }
    }
  }
  echo "done\n";
} catch(Throwable $e){ echo get_class($e); }

