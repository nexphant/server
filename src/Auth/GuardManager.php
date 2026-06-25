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

/**
 * GuardManager — manages multiple named authentication guards.
 *
 * Usage:
 *   $guards->guard('web')->login($user);
 *   $guards->guard('api')->check();
 */
class GuardManager
{
    /** @var array<string, GuardInterface> */
    private array $guards = [];

    private string $default = 'web';

    public function register(string $name, GuardInterface $guard): void
    {
        $this->guards[$name] = $guard;
    }

    public function setDefault(string $name): void
    {
        $this->default = $name;
    }

    public function guard(?string $name = null): GuardInterface
    {
        $name ??= $this->default;
        if (!isset($this->guards[$name])) {
            throw new \RuntimeException("Auth guard [{$name}] not registered.");
        }
        return $this->guards[$name];
    }

    public function check(?string $guard = null): bool  { return $this->guard($guard)->check(); }
    public function guest(?string $guard = null): bool  { return $this->guard($guard)->guest(); }
    public function user(?string $guard = null): ?array { return $this->guard($guard)->user(); }
    public function id(?string $guard = null): int|string|null { return $this->guard($guard)->id(); }
}
