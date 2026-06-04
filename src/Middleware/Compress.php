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

class Compress {
    private int $minSize;

    public function __construct(int $minSize = 1024) {
        $this->minSize = $minSize;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): void {
        $acceptEncoding = $request->header('accept-encoding', '');
        if (!str_contains($acceptEncoding, 'gzip')) {
            return;
        }
    }
}
