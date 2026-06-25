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

/**
 * SessionLock — provides advisory locking for concurrent session access.
 *
 * Uses file-based locks as a cross-driver solution (no DB/APCu dependency).
 * For distributed setups, replace with a Redis-based lock.
 */
class SessionLock
{
    private array $handles = [];

    public function __construct(private readonly string $lockDir)
    {
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0700, true);
        }
    }

    /**
     * Acquire an exclusive lock for a session ID.
     * Blocks until the lock is obtained or timeout is reached.
     */
    public function acquire(string $id, int $timeoutSeconds = 5): bool
    {
        $path   = $this->lockPath($id);
        $fh     = fopen($path, 'c');
        if ($fh === false) return false;

        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                $this->handles[$id] = $fh;
                return true;
            }
            usleep(10_000); // 10ms back-off
        }

        fclose($fh);
        return false;
    }

    /**
     * Release the lock for a session ID.
     */
    public function release(string $id): void
    {
        if (!isset($this->handles[$id])) return;
        flock($this->handles[$id], LOCK_UN);
        fclose($this->handles[$id]);
        unset($this->handles[$id]);
    }

    /**
     * Release all held locks.
     */
    public function releaseAll(): void
    {
        foreach (array_keys($this->handles) as $id) {
            $this->release($id);
        }
    }

    private function lockPath(string $id): string
    {
        return $this->lockDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) . '.lock';
    }
}
