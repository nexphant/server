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
 * File-based session storage.
 *
 * Sessions are stored as serialized files under a configurable directory.
 * Each file is named by session ID; GC removes expired files.
 */
class FileSessionStorage implements SessionStorageInterface
{
    private string $path;
    private string $prefix;

    public function __construct(string $path = '', string $prefix = 'sess_')
    {
        $this->path   = rtrim($path !== '' ? $path : sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $this->prefix = $prefix;

        if (!is_dir($this->path) && !mkdir($this->path, 0700, true)) {
            throw new \RuntimeException("Cannot create session directory: {$this->path}");
        }
    }

    public function read(string $id): array
    {
        $file = $this->filePath($id);
        if (!is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }

        $data = @unserialize($raw);
        return is_array($data) ? $data : [];
    }

    public function write(string $id, array $data, int $ttl): void
    {
        $file = $this->filePath($id);
        // Atomic write via temp file + rename
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, serialize($data), LOCK_EX);
        rename($tmp, $file);
        // Store expiry in mtime for GC
        touch($file, time() + $ttl);
    }

    public function destroy(string $id): void
    {
        $file = $this->filePath($id);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function gc(int $maxLifetime): void
    {
        $now     = time();
        $pattern = $this->path . DIRECTORY_SEPARATOR . $this->prefix . '*';
        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $now) {
                @unlink($file);
            }
        }
    }

    private function filePath(string $id): string
    {
        // Validate ID to prevent path traversal
        if (!preg_match('/^[0-9a-f]{64}$/', $id)) {
            throw new \InvalidArgumentException('Invalid session ID');
        }
        return $this->path . DIRECTORY_SEPARATOR . $this->prefix . $id;
    }
}
