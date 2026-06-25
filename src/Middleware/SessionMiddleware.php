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
use Nexphant\Server\Session\SessionManager;

/**
 * Session middleware.
 *
 * - Starts the session from the incoming Cookie header
 * - Attaches the Session instance as request attribute 'session'
 * - Saves session data and sets the session cookie on the response
 *
 * Cookie flags applied: HttpOnly, SameSite, Secure (configurable).
 */
class SessionMiddleware
{
    private SessionManager $manager;
    private string $cookiePath;
    private string $cookieDomain;
    private bool $cookieSecure;
    private string $cookieSameSite;
    private int $ttl;

    public function __construct(SessionManager $manager, array $options = [])
    {
        $this->manager        = $manager;
        $this->cookiePath     = $options['cookie_path']    ?? '/';
        $this->cookieDomain   = $options['cookie_domain']  ?? '';
        $this->cookieSecure   = (bool) ($options['cookie_secure']   ?? true);
        $this->cookieSameSite = $options['cookie_samesite'] ?? 'Lax';
        $this->ttl            = (int) ($options['ttl'] ?? 7200);
    }

    public function __invoke(ServerRequest $request, ServerResponse $response, callable $next): mixed
    {
        $ip      = $request->remoteAddr ?? '';
        $ua      = $request->header('user-agent', '');
        $cookie  = $request->header('cookie', '');

        /** @var Session $session */
        $session = $this->manager->make($ip, $ua);
        $session->start($cookie ?? '');

        // Attach to request attributes so handlers can access it
        $request->setAttribute('session', $session);

        $result = $next($request, $response);

        // Age flash data before saving
        $session->ageFlash();

        // Persist session data
        $session->save();

        // Write session cookie
        $cookieOptions = [
            'max_age'  => $this->ttl,
            'path'     => $this->cookiePath,
            'httponly' => true,
            'samesite' => $this->cookieSameSite,
        ];
        if ($this->cookieSecure) {
            $cookieOptions['secure'] = true;
        }
        if ($this->cookieDomain !== '') {
            $cookieOptions['domain'] = $this->cookieDomain;
        }

        $response->cookie($session->cookieName(), $session->cookieValue(), $cookieOptions);

        return $result;
    }
}
