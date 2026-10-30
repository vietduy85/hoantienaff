<?php
require "vendor/autoload.php";
$c=file_get_contents("app/Services/ShopeeFashionAlertService.php");
echo (strpos($c,"adminTestAlert")===false?"n":"y");

