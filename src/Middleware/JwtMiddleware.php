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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nexphant\Server\Auth\JwtAdapter;

/**
 * JwtMiddleware — validates Bearer JWT tokens and sets auth on request attributes.
 */
class JwtMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly JwtAdapter $jwt) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');

        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
            try {
                $payload = $this->jwt->decode($token);
                $request = $request->withAttribute('jwt_payload', $payload)
                                   ->withAttribute('auth_user', $payload);
            } catch (\RuntimeException) {
                // Invalid token — continue unauthenticated
            }
        }

        return $handler->handle($request);
    }
}
