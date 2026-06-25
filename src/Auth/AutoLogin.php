<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Auth;

use Nexphant\Server\Auth\AuthManager;
use Nexphant\Server\Session\SessionInterface;

/**
 * AutoLogin — restores authentication from a remember-me cookie on each request.
 *
 * Plug this into your bootstrap or SessionMiddleware pipeline.
 */
class AutoLogin
{
    public function __construct(
        private readonly AuthManager $auth,
    ) {}

    /**
     * Attempt to restore the session from a remember-me cookie.
     *
     * @param array $cookies  The request cookie array ($_COOKIE or parsed cookies)
     */
    public function attempt(array $cookies, SessionInterface $session): bool
    {
        if ($this->auth->check()) {
            return true; // already authenticated
        }

        $token = $cookies['remember_token'] ?? null;
        if ($token === null) {
            return false;
        }

        return $this->auth->loginViaRemember($token, $session);
    }
}
