<?php
require 'vendor/autoload.php';
 = require 'bootstrap/app.php';
 = ->make(Illuminate\Contracts\Console\Kernel::class);
->bootstrap();
 = Illuminate\Support\Facades\DB::select('SHOW COLUMNS FROM affiliate_order_items');
foreach( as ){ echo ->Field.PHP_EOL; }
