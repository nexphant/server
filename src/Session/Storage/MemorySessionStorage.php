<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Session\Storage;

use Nexphant\Server\Session\SessionStorageInterface;

/**
 * MemorySessionStorage — per-request in-memory storage (no TTL enforcement).
 * Suitable for single-request tests and Fiber/async contexts where
 * each worker maintains its own session map.
 */
class MemorySessionStorage implements SessionStorageInterface
{
    /** @var array<string, array<string,mixed>> */
    private array $store = [];

    public function read(string $id): array
    {
        return $this->store[$id] ?? [];
    }

    public function write(string $id, array $data, int $ttl = 0): void
    {
        $this->store[$id] = $data;
    }

    public function destroy(string $id): void
    {
        unset($this->store[$id]);
    }

    public function gc(int $maxLifetime): void
    {
        // No TTL tracking — memory driver is ephemeral per process
    }
}
