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
use Nexphant\Database\DB;

/**
 * DatabaseSessionStorage — stores sessions in a database table.
 *
 * Table schema:
 *   CREATE TABLE sessions (
 *     id VARCHAR(128) PRIMARY KEY,
 *     payload TEXT NOT NULL,
 *     last_activity INT NOT NULL
 *   )
 */
class DatabaseSessionStorage implements SessionStorageInterface
{
    public function __construct(
        private readonly string $table      = 'sessions',
        private readonly string $connection = 'default',
    ) {}

    public function read(string $id): array
    {
        $row = DB::table($this->table)
            ->connection($this->connection)
            ->where('id', '=', $id)
            ->first();

        if (!$row) return [];

        $data = @unserialize($row['payload'] ?? '');
        return is_array($data) ? $data : [];
    }

    public function write(string $id, array $data): void
    {
        $payload = serialize($data);
        $now     = time();

        $exists = DB::table($this->table)
            ->connection($this->connection)
            ->where('id', '=', $id)
            ->exists();

        if ($exists) {
            DB::table($this->table)
                ->connection($this->connection)
                ->where('id', '=', $id)
                ->update(['payload' => $payload, 'last_activity' => $now]);
        } else {
            DB::table($this->table)
                ->connection($this->connection)
                ->insert(['id' => $id, 'payload' => $payload, 'last_activity' => $now]);
        }
    }

    public function destroy(string $id): void
    {
        DB::table($this->table)
            ->connection($this->connection)
            ->where('id', '=', $id)
            ->delete();
    }

    public function gc(int $maxLifetime): void
    {
        $cutoff = time() - $maxLifetime;
        DB::table($this->table)
            ->connection($this->connection)
            ->where('last_activity', '<', $cutoff)
            ->delete();
    }
}
