<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Server\Middleware;

use Nexph\Server\ServerRequest;
use Nexph\Server\ServerResponse;

class Logger {
    private string $format;

    public function __construct(string $format = 'combined') {
        $this->format = $format;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): void {
        $start = $request->time;

        register_shutdown_function(function () use ($request, $response, $start) {
            $duration = (microtime(true) - $start) * 1000;
            $this->log($request, $response, $duration);
        });
    }

    private function log(ServerRequest $request, ServerResponse $response, float $duration): void {
        $line = sprintf(
            '%s - - [%s] "%s %s" %d %d %.2fms',
            $request->remoteAddr,
            date('d/M/Y:H:i:s O'),
            $request->method,
            $request->uri,
            $response->getStatus(),
            strlen($response->getBody()),
            $duration
        );

        echo $line . "\n";
    }
}
