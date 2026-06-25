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

class RedisSessionStorage implements SessionStorageInterface
{
    /** @var \Redis */
    private mixed $redis;
    private string $prefix;

    public function __construct(mixed $redis, string $prefix = 'sess:')
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('Redis extension not loaded');
        }
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function read(string $id): array
    {
        $value = $this->redis->get($this->prefix . $id);
        if ($value === false || $value === null) {
            return [];
        }
        $data = @unserialize((string) $value);
        return is_array($data) ? $data : [];
    }

    public function write(string $id, array $data, int $ttl): void
    {
        $this->redis->setex($this->prefix . $id, $ttl, serialize($data));
    }

    public function destroy(string $id): void
    {
        $this->redis->del($this->prefix . $id);
    }

    public function gc(int $maxLifetime): void
    {
        // Redis handles TTL-based expiry automatically
    }
}
