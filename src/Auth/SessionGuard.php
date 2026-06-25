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

use Nexphant\Server\Session\SessionInterface;

/**
 * SessionGuard — session-based authentication guard.
 */
class SessionGuard implements GuardInterface
{
    private ?array $user = null;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly string $key = '_auth_user',
    ) {
        $this->user = $this->session->get($this->key);
    }

    public function check(): bool  { return $this->user !== null; }
    public function guest(): bool  { return $this->user === null; }
    public function user(): ?array { return $this->user; }

    public function id(): int|string|null
    {
        return $this->user['id'] ?? null;
    }

    public function login(array $user): void
    {
        $this->user = $user;
        $this->session->put($this->key, $user);
    }

    public function logout(): void
    {
        $this->user = null;
        $this->session->forget($this->key);
    }
}
