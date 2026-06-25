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

    private Session $session;
    /** @var callable|null */
    private mixed $userResolver;

    public function __construct(Session $session, ?callable $userResolver = null)
    {
        $this->session      = $session;
        $this->userResolver = $userResolver;
    }

    // -------------------------------------------------------------------------
    // Login / Logout
    // -------------------------------------------------------------------------

    /**
     * Log in a user.
     *
     * @param int|string $id        User identifier stored in session
     * @param array      $payload   Extra data to store (e.g. name, roles)
     * @param bool       $regenerate Regenerate session ID to prevent fixation
     */
    public function login(int|string $id, array $payload = [], bool $regenerate = true): void
    {
        if ($regenerate) {
            $this->session->regenerate();
        }
        $this->session->set(self::KEY_ID,    $id);
        $this->session->set(self::KEY_USER,  $payload);
        $this->session->set(self::KEY_LOGIN, time());
    }

    public function logout(): void
    {
        $this->session->remove(self::KEY_ID);
        $this->session->remove(self::KEY_USER);
        $this->session->remove(self::KEY_LOGIN);
        $this->session->regenerate();
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

    /** Return stored payload, optionally fetching via resolver. */
    public function user(): mixed
    {
        if ($this->userResolver !== null && $this->check()) {
            return ($this->userResolver)($this->id());
        }
        return $this->session->get(self::KEY_USER);
    }

    /** Return a single field from the stored payload. */
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
    // Roles / abilities (simple array-based)
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
