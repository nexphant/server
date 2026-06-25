<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Session;

interface SessionInterface
{
    public function start(string $id): void;
    public function id(): string;
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function put(string $key, mixed $value): void;
    public function has(string $key): bool;
    public function remove(string $key): void;
    public function forget(string $key): void;
    public function all(): array;
    public function clear(): void;
    public function invalidate(): string;
    public function save(): void;
    public function destroy(): void;
    public function regenerate(): string;
    // Flash
    public function flash(string $key, mixed $value): void;
    public function now(string $key, mixed $value): void;
    public function reflash(): void;
    public function keep(string ...$keys): void;
    public function getFlash(string $key, mixed $default = null): mixed;
    public function hasFlash(string $key): bool;
    // Old input
    public function old(string $key, mixed $default = null): mixed;
    public function withInput(array $input): void;
}
