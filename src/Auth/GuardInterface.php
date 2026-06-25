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
 * GuardInterface — contract for authentication guards.
 */
interface GuardInterface
{
    public function check(): bool;
    public function guest(): bool;
    public function user(): ?array;
    public function id(): int|string|null;
    public function login(array $user): void;
    public function logout(): void;
}
