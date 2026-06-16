<?php

use nexphant\App;

require __DIR__ . '/../vendor/autoload.php';

$app = App::create([
    'runtime_safety' => false,
    'keep_alive_timeout' => 30,
    'max_requests' => 10000,
]);

$app->fastJson('GET', '/fast', ['status' => 'ok', 'timestamp' => time(), 'mode' => 'fast']);
$app->get('/normal', fn() => ['status' => 'ok', 'timestamp' => time(), 'mode' => 'normal']);

echo "Benchmark server started on http://127.0.0.1:8080\n";
echo "Routes:\n";
echo "  GET /fast   - FastPath (prebuilt response)\n";
echo "  GET /normal - Normal route (Request/Response objects)\n";
echo "\nBenchmark commands:\n";
echo "  wrk -t4 -c100 -d30s http://127.0.0.1:8080/fast\n";
echo "  wrk -t4 -c100 -d30s http://127.0.0.1:8080/normal\n";

$app->listen(8080);
