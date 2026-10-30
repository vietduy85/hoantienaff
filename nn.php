<?php
require "vendor/autoload.php";
$c=file_get_contents("app/Http/Controllers/DashboardController.php");
echo (strpos($c,"shopeeFashionAlertTest")===false?"n":"y");

