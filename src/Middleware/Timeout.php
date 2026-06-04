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

class Timeout {
    private float $seconds;

    public function __construct(float $seconds = 30.0) {
        $this->seconds = $seconds;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): \Generator {
        $elapsed = microtime(true) - $request->time;
        if ($elapsed > $this->seconds) {
            $response->json(['error' => 'Request timeout'], 408);
        }
        yield;
    }
}
