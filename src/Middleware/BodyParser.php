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

class BodyParser {
    private int $maxSize;

    public function __construct(int $maxSize = 10 * 1024 * 1024) {
        $this->maxSize = $maxSize;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): void {
        if (strlen($request->body) > $this->maxSize) {
            $response->json(['error' => 'Request body too large'], 413);
        }
    }
}
