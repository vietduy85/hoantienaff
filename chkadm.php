<?php
require "vendor/autoload.php";
$p="app/Services/ShopeeFashionAlertService.php";
$c=file_get_contents($p);
echo (strpos($c,"adminTestAlert")===false?"n":"y");

