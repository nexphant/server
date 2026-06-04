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

class Cors {
    private array $options;

    public function __construct(array $options = []) {
        $this->options = array_merge([
            'origin' => '*',
            'methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'headers' => 'Content-Type, Authorization, X-Requested-With',
            'credentials' => false,
            'max_age' => 86400,
        ], $options);
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): bool {
        $origin = $this->options['origin'];
        if (is_array($origin)) {
            $requestOrigin = $request->header('origin', '');
            $origin = in_array($requestOrigin, $origin) ? $requestOrigin : $origin[0];
        }

        $response->header('Access-Control-Allow-Origin', $origin);
        $response->header('Access-Control-Allow-Methods', $this->options['methods']);
        $response->header('Access-Control-Allow-Headers', $this->options['headers']);

        if ($this->options['credentials']) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        if ($request->method === 'OPTIONS') {
            $response->header('Access-Control-Max-Age', (string) $this->options['max_age']);
            $response->status(204)->body('');
            return false;
        }

        return true;
    }
}
