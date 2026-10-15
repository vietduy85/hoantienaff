<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make("Illuminate\Contracts\Console\Kernel")->bootstrap();
$user = App\Models\User::find(5);
if (!$user) { echo "no user"; exit; }
$optimizer = app(\App\Services\CreditCard\Optimizer\PortfolioOptimizerService::class);
$result = $optimizer->optimize($user->id, "owned", "balance", [], 1);
var_dump(count($result["plans"]));
var_dump(array_column($result["plans"], "total_cashback"));
var_dump($result["best_cashback"]);

