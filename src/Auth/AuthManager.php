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

use Nexphant\Server\Cookie\Cookie;
use Nexphant\Server\Cookie\CookieJar;
use Nexphant\Server\Session\Session;

/**
 * Simple session-based authentication manager.
 *
 * Stores the authenticated user ID and payload in the session.
 * Use your own user lookup callback for flexibility.
 */
class AuthManager
{
    private const KEY_ID      = '_auth_id';
    private const KEY_USER    = '_auth_user';
    private const KEY_LOGIN   = '_auth_login_at';
    private const REMEMBER_COOKIE = 'nx_remember';

    private Session      $session;
    private mixed        $userResolver;
    private ?CookieJar   $cookieJar;
    private string       $rememberSecret;

    public function __construct(
        Session      $session,
        ?callable    $userResolver   = null,
        ?CookieJar   $cookieJar      = null,
        string       $rememberSecret = '',
    ) {
        $this->session        = $session;
        $this->userResolver   = $userResolver;
        $this->cookieJar      = $cookieJar;
        $this->rememberSecret = $rememberSecret;
    }

    // -------------------------------------------------------------------------
    // Login / Logout
    // -------------------------------------------------------------------------

    /**
     * @param int|string $id
     * @param array      $payload     Extra data to store (e.g. name, roles)
     * @param bool       $regenerate  Regenerate session ID to prevent fixation
     * @param bool       $remember    Issue a long-lived remember-me cookie
     * @param int        $rememberTtl Remember-me cookie lifetime in seconds (default 30 days)
     */
    public function login(
        int|string $id,
        array $payload      = [],
        bool  $regenerate   = true,
        bool  $remember     = false,
        int   $rememberTtl  = 2_592_000,
    ): void {
        if ($regenerate) {
            $this->session->regenerate();
        }
        $this->session->set(self::KEY_ID,    $id);
        $this->session->set(self::KEY_USER,  $payload);
        $this->session->set(self::KEY_LOGIN, time());

        if ($remember) {
            $this->issueRememberToken($id, $rememberTtl);
        }
    }

    public function logout(): void
    {
        $this->session->remove(self::KEY_ID);
        $this->session->remove(self::KEY_USER);
        $this->session->remove(self::KEY_LOGIN);
        $this->session->remove('_remember_token');
        $this->session->regenerate();

        // Expire the remember cookie
        if ($this->cookieJar !== null) {
            $this->cookieJar->forget(self::REMEMBER_COOKIE);
        }
    }

    // -------------------------------------------------------------------------
    // Remember Me
    // -------------------------------------------------------------------------

    private function issueRememberToken(int|string $id, int $ttl): void
    {
        $token  = bin2hex(random_bytes(32));
        $secret = $this->rememberSecret !== '' ? $this->rememberSecret : 'nx_remember_default';
        $signed = $token . '.' . hash_hmac('sha256', (string) $id . '|' . $token, $secret);

        $this->session->set('_remember_token', $token);

        if ($this->cookieJar !== null) {
            $this->cookieJar->queue(Cookie::make(self::REMEMBER_COOKIE, $signed, $ttl));
        }
    }

    /**
     * Verify a remember-me cookie value against a known user ID and stored token.
     * Returns true if the signature is valid.
     */
    public function verifyRememberToken(int|string $id, string $storedToken, string $rawCookie): bool
    {
        $pos = strrpos($rawCookie, '.');
        if ($pos === false) {
            return false;
        }
        $token  = substr($rawCookie, 0, $pos);
        $sig    = substr($rawCookie, $pos + 1);
        $secret = $this->rememberSecret !== '' ? $this->rememberSecret : 'nx_remember_default';
        $expected = hash_hmac('sha256', (string) $id . '|' . $token, $secret);

        return hash_equals($expected, $sig) && hash_equals($storedToken, $token);
    }

    // -------------------------------------------------------------------------
    // Checks
    // -------------------------------------------------------------------------

    public function check(): bool
    {
        return $this->session->has(self::KEY_ID);
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    // -------------------------------------------------------------------------
    // User data
    // -------------------------------------------------------------------------

    public function id(): int|string|null
    {
        return $this->session->get(self::KEY_ID);
    }

    public function user(): mixed
    {
        if ($this->userResolver !== null && $this->check()) {
            return ($this->userResolver)($this->id());
        }
        return $this->session->get(self::KEY_USER);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payload = $this->session->get(self::KEY_USER, []);
        return is_array($payload) ? ($payload[$key] ?? $default) : $default;
    }

    public function loginAt(): ?int
    {
        return $this->session->get(self::KEY_LOGIN);
    }

    // -------------------------------------------------------------------------
    // Roles / abilities
    // -------------------------------------------------------------------------

    public function hasRole(string $role): bool
    {
        $roles = $this->get('roles', []);
        return is_array($roles) && in_array($role, $roles, true);
    }

    public function hasAnyRole(string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) return true;
        }
        return false;
    }

    public function can(string $ability): bool
    {
        $abilities = $this->get('abilities', []);
        return is_array($abilities) && in_array($ability, $abilities, true);
    }
}
