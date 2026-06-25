<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Middleware;

use Nexphant\Server\ServerRequest;
use Nexphant\Server\ServerResponse;

/**
 * Content Security Policy (CSP) middleware.
 *
 * Adds a Content-Security-Policy header built from a directive array.
 *
 * Usage:
 *   new CspMiddleware([
 *       'default-src' => ["'self'"],
 *       'script-src'  => ["'self'", 'https://cdn.example.com'],
 *       'img-src'     => ["'self'", 'data:'],
 *   ])
 */
class CspMiddleware
{
    private string $policy;
    private bool   $reportOnly;

    /**
     * @param array<string, string[]> $directives
     */
    public function __construct(array $directives = [], bool $reportOnly = false)
    {
        $this->reportOnly = $reportOnly;
        $parts = [];
        foreach ($directives as $directive => $values) {
            $parts[] = $directive . (empty($values) ? '' : ' ' . implode(' ', $values));
        }
        $this->policy = implode('; ', $parts);
    }

    public function __invoke(ServerRequest $request, ServerResponse $response, callable $next): mixed
    {
        $result = $next($request, $response);

        $header = $this->reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        if ($this->policy !== '') {
            $response->header($header, $this->policy);
        }

        return $result;
    }
}
