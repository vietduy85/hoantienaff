<?php
require "vendor/autoload.php";
$r=new ReflectionClass("App\Services\ShopeeFashionAlertService");
var_dump($r->hasMethod("adminTestAlert"));

