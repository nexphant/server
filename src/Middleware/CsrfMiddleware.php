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
use Nexphant\Server\Session\Session;

/**
 * CSRF protection middleware.
 *
 * Requires SessionMiddleware to run first (session attribute must be set).
 *
 * - Generates a per-session CSRF token stored in the session
 * - Validates the token on state-changing methods (POST, PUT, PATCH, DELETE)
 * - Token can be sent as a header (X-CSRF-Token) or form field (_token)
 */
class CsrfMiddleware
{
    private const TOKEN_KEY    = '_csrf_token';
    private const HEADER_NAME  = 'x-csrf-token';
    private const FIELD_NAME   = '_token';
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __invoke(ServerRequest $request, ServerResponse $response, callable $next): mixed
    {
        /** @var Session|null $session */
        $session = $request->getAttribute('session');

        if ($session === null) {
            throw new \RuntimeException('CsrfMiddleware requires SessionMiddleware to run first');
        }

        // Ensure token exists in session
        if (!$session->has(self::TOKEN_KEY)) {
            $session->set(self::TOKEN_KEY, bin2hex(random_bytes(32)));
        }

        $method = strtoupper($request->method ?? 'GET');

        if (!in_array($method, self::SAFE_METHODS, true)) {
            $sessionToken = $session->get(self::TOKEN_KEY, '');

            // Check header first, then form field
            $requestToken = $request->header(self::HEADER_NAME)
                ?? $request->post(self::FIELD_NAME);

            if (!is_string($requestToken) || !hash_equals((string) $sessionToken, $requestToken)) {
                $response->json(['error' => 'CSRF token mismatch'], 419);
                return $response;
            }
        }

        // Expose token in response header for SPA/XHR clients
        $response->header('X-CSRF-Token', (string) $session->get(self::TOKEN_KEY, ''));

        return $next($request, $response);
    }
}
