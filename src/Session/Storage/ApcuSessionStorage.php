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

class ApcuSessionStorage implements SessionStorageInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'sess:')
    {
        if (!function_exists('apcu_fetch') || !apcu_enabled()) {
            throw new \RuntimeException('APCu extension not available or not enabled');
        }
        $this->prefix = $prefix;
    }

    public function read(string $id): array
    {
        $value = apcu_fetch($this->prefix . $id, $success);
        if (!$success || !is_array($value)) {
            return [];
        }
        return $value;
    }

    public function write(string $id, array $data, int $ttl): void
    {
        apcu_store($this->prefix . $id, $data, $ttl);
    }

    public function destroy(string $id): void
    {
        apcu_delete($this->prefix . $id);
    }

    public function gc(int $maxLifetime): void
    {
        // APCu handles TTL-based expiry automatically
    }
}
