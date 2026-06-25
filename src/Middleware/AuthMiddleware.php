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

use Nexphant\Server\Auth\AuthManager;
use Nexphant\Server\ServerRequest;
use Nexphant\Server\ServerResponse;

/**
 * Authentication guard middleware.
 *
 * Rejects unauthenticated requests with 401 JSON or redirects to a login URL.
 */
class AuthMiddleware
{
    public function __construct(
        private string $redirectTo = '',
        private int    $status     = 401,
    ) {}

    public function __invoke(ServerRequest $request, ServerResponse $response, callable $next): mixed
    {
        /** @var AuthManager|null $auth */
        $auth = $request->getAttribute('auth');

        if ($auth === null || !$auth->check()) {
            if ($this->redirectTo !== '') {
                $response->status(302)->header('Location', $this->redirectTo);
                return $response;
            }
            $response->json(['error' => 'Unauthenticated'], $this->status);
            return $response;
        }

        return $next($request, $response);
    }
}
