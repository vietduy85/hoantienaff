<?php
require "vendor/autoload.php";
$p="app/Http/Controllers/DashboardController.php";
$c=file_get_contents($p);
echo (strpos($c,"adminTestAlert")===false?"n":"y");

