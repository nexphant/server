<?php

use nexphant\App;

require __DIR__ . '/../vendor/autoload.php';

$app = App::create();

$app->fastJson('GET', '/fast-json', ['message' => 'FastPath JSON', 'timestamp' => time()]);
$app->fastText('GET', '/fast-text', 'FastPath Text Response');

$app->get('/normal', fn() => ['message' => 'Normal route with middleware']);

$app->use(function ($request, $next) {
    error_log('Middleware executed');
    return $next($request);
});

echo "Test server started on http://127.0.0.1:8080\n";
echo "FastPath routes:\n";
echo "  GET /fast-json\n";
echo "  GET /fast-text\n";
echo "Normal routes:\n";
echo "  GET /normal\n";

$app->listen(8080);
