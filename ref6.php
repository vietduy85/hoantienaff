<?php
require "vendor/autoload.php";
$app=require"bootstrap/app.php";
$k=$app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();
$r=new ReflectionClass("App\Services\ShopeeFashionAlertService");
var_dump($r->hasMethod("adminTestAlert"));

