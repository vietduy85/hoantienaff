<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$router = app('router');
$routes = $router->getRoutes();
foreach ($routes as $r) {
    if (str_contains($r->uri, 'extension')) {
        echo "uri=" . $r->uri . " methods=" . json_encode($r->methods) . " middleware=" . json_encode($r->middleware()) . " name=" . ($r->getName() ?? 'none') . "\n";
    }
}

echo "\n--- all api routes ---\n";
foreach ($routes as $r) {
    if (str_contains($r->uri, 'api')) {
        echo "uri=" . $r->uri . " methods=" . json_encode($r->methods) . " middleware=" . json_encode($r->middleware()) . "\n";
    }
}
