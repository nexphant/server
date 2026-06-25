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
 * Security Headers middleware.
 *
 * Adds OWASP-recommended security headers to every response.
 * Individual headers can be overridden or disabled via constructor options.
 */
class SecurityHeadersMiddleware
{
    private array $headers;

    public function __construct(array $overrides = [])
    {
        $defaults = [
            'X-Content-Type-Options'           => 'nosniff',
            'X-Frame-Options'                  => 'DENY',
            'X-XSS-Protection'                 => '0',               // Modern: disabled in favour of CSP
            'Referrer-Policy'                  => 'strict-origin-when-cross-origin',
            'Permissions-Policy'               => 'geolocation=(), microphone=(), camera=()',
            'Strict-Transport-Security'        => 'max-age=31536000; includeSubDomains',
            'Cross-Origin-Opener-Policy'       => 'same-origin',
            'Cross-Origin-Resource-Policy'     => 'same-origin',
        ];

        // false = remove the header; string = override value
        foreach ($overrides as $name => $value) {
            if ($value === false) {
                unset($defaults[$name]);
            } else {
                $defaults[$name] = (string) $value;
            }
        }

        $this->headers = $defaults;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response, callable $next): mixed
    {
        $result = $next($request, $response);
        $response->headers($this->headers);
        return $result;
    }
}
