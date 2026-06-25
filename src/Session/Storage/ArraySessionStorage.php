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
 * In-memory session storage. For testing and single-worker scenarios only.
 * Data is lost when the process restarts.
 */
class ArraySessionStorage implements SessionStorageInterface
{
    /** @var array<string, array{data: array<string,mixed>, expires: int}> */
    private array $store = [];

    public function read(string $id): array
    {
        if (!isset($this->store[$id])) {
            return [];
        }
        if (time() > $this->store[$id]['expires']) {
            unset($this->store[$id]);
            return [];
        }
        return $this->store[$id]['data'];
    }

    public function write(string $id, array $data, int $ttl): void
    {
        $this->store[$id] = [
            'data'    => $data,
            'expires' => time() + $ttl,
        ];
    }

    public function destroy(string $id): void
    {
        unset($this->store[$id]);
    }

    public function gc(int $maxLifetime): void
    {
        $now = time();
        foreach ($this->store as $id => $entry) {
            if ($now > $entry['expires']) {
                unset($this->store[$id]);
            }
        }
    }
}
