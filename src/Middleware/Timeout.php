<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace nexphant\Server\Middleware;

use nexphant\Server\ServerRequest;
use nexphant\Server\ServerResponse;

class Timeout
{
    private float $seconds;

    public function __construct(float $seconds = 30.0)
    {
        $this->seconds = $seconds;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): \Generator
    {
        $elapsed = microtime(true) - $request->time;
        if ($elapsed > $this->seconds) {
            $response->json(['error' => 'Request timeout'], 408);
        }
        yield;
    }
}
