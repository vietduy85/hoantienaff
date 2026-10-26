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
  // search for shopeefood hints in any string value
  $hits=[];
  foreach($items as $it){
    if(!is_array($it)) continue;
    $json = json_encode($it);
    if(stripos($json,"shopeefood")!==false || stripos($json,"food")!==false || stripos($json,"now-food")!==false){
      $hits[] = substr($json,0,120);
      if(count($hits)>=3) break;
    }
  }
  echo "total:".count($items).PHP_EOL;
  echo empty($hits)?"no_food_hints":"has_hints".PHP_EOL;
  if(!empty($hits)){ foreach($hits as $h) echo $h.PHP_EOL; }
} catch(Throwable $e){ echo get_class($e); }

