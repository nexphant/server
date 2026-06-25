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

interface SessionStorageInterface
{
    public function read(string $id): array;
    public function write(string $id, array $data, int $ttl): void;
    public function destroy(string $id): void;
    public function gc(int $maxLifetime): void;
}
