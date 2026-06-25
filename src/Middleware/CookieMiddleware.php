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

use Nexphant\Server\Cookie\Cookie;
use Nexphant\Server\Cookie\CookieJar;
use Nexphant\Server\ServerRequest;
use Nexphant\Server\ServerResponse;

/**
 * Applies queued cookies from CookieJar to the response.
 *
 * Mount after any middleware that queues cookies (e.g. SessionMiddleware).
 */
class CookieMiddleware
{
    public function __construct(private CookieJar $jar) {}

    public function __invoke(ServerRequest $request, ServerResponse $response, callable $next): mixed
    {
        // Attach jar to request so handlers can queue cookies
        $request->setAttribute('cookies', $this->jar);

        $result = $next($request, $response);

        // Flush all queued cookies onto the response
        foreach ($this->jar->queued() as $cookie) {
            $response->cookie(
                $cookie->getName(),
                $cookie->getValue(),
                [
                    'expires'  => $cookie->getExpires(),
                    'max_age'  => $cookie->getMaxAge(),
                    'path'     => $cookie->getPath(),
                    'domain'   => $cookie->getDomain(),
                    'secure'   => $cookie->isSecure(),
                    'httponly' => $cookie->isHttpOnly(),
                    'samesite' => $cookie->getSameSite(),
                ]
            );
        }

        $this->jar->flush();

        return $result;
    }
}
