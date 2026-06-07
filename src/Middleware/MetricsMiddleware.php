<?php

namespace Nexph\Server\Middleware;

use Nexph\Runtime\Metrics\WorkerMetrics;

class MetricsMiddleware
{
    public function __invoke($request, callable $next)
    {
        if ($request->path() === '/metrics') {
            $metrics = WorkerMetrics::getAllMetrics();
            return [
                'workers' => $metrics,
                'timestamp' => time(),
            ];
        }

        if ($request->path() === '/runtime/workers') {
            return WorkerMetrics::getAllMetrics();
        }

        return $next($request);
    }
}
