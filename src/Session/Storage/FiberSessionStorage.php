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
 * FiberSessionStorage — Fiber-safe session storage using WeakMap keyed by current Fiber.
 *
 * Each Fiber gets its own isolated session store. Falls back to a shared
 * ArraySessionStorage when running outside a Fiber context (e.g. traditional request).
 *
 * This prevents session data leakage between concurrent Fibers in a single process.
 */
class FiberSessionStorage implements SessionStorageInterface
{
    /** @var \WeakMap<\Fiber, ArraySessionStorage> */
    private \WeakMap $fiberStores;

    /** Fallback store for non-Fiber context */
    private ArraySessionStorage $globalStore;

    public function __construct()
    {
        $this->fiberStores = new \WeakMap();
        $this->globalStore = new ArraySessionStorage();
    }

    public function read(string $id): array
    {
        return $this->store()->read($id);
    }

    public function write(string $id, array $data, int $ttl = 0): void
    {
        $this->store()->write($id, $data, $ttl);
    }

    public function destroy(string $id): void
    {
        $this->store()->destroy($id);
    }

    public function gc(int $maxLifetime): void
    {
        $this->store()->gc($maxLifetime);
    }

    // -------------------------------------------------------------------------

    /**
     * Returns the storage instance for the current Fiber (or global if none).
     */
    private function store(): ArraySessionStorage
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return $this->globalStore;
        }

        if (!isset($this->fiberStores[$fiber])) {
            $this->fiberStores[$fiber] = new ArraySessionStorage();
        }

        return $this->fiberStores[$fiber];
    }
}
