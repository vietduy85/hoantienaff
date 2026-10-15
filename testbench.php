<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make("Illuminate\Contracts\Console\Kernel")->bootstrap();
$u = App\Models\User::find(5);
var_dump($u ? $u->id : null);

