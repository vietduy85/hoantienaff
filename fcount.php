<?php
require "vendor/autoload.php";
$c=file_get_contents("app/Services/ShopeeFashionAlertService.php");
echo substr_count($c,"function ");

