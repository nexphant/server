<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Server;

use Nexph\Database\DB;

class AsyncIO {
    private static ?EventLoop $loop = null;

    public static function setLoop(EventLoop $loop): void {
        self::$loop = $loop;
    }

    public static function readFile(string $path): \Generator {
        $deferred = new Deferred();

        if (!file_exists($path)) {
            $deferred->resolve(null);
            return yield $deferred;
        }

        // Non-blocking file read simulation
        self::$loop?->defer(function () use ($path, $deferred) {
            $content = @file_get_contents($path);
            $deferred->resolve($content !== false ? $content : null);
        });

        return yield $deferred;
    }

    public static function writeFile(string $path, string $content): \Generator {
        $deferred = new Deferred();

        self::$loop?->defer(function () use ($path, $content, $deferred) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $result = @file_put_contents($path, $content, LOCK_EX);
            $deferred->resolve($result !== false);
        });

        return yield $deferred;
    }

    public static function httpRequest(string $method, string $url, array $options = []): \Generator {
        $deferred = new Deferred();

        self::$loop?->defer(function () use ($method, $url, $options, $deferred) {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => self::buildHeaders($options['headers'] ?? []),
                    'content' => $options['body'] ?? '',
                    'timeout' => $options['timeout'] ?? 30,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => $options['verify_ssl'] ?? true,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];

            $status = 0;
            if (!empty($headers) && preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headers[0], $m)) {
                $status = (int) $m[1];
            }

            $deferred->resolve([
                'status' => $status,
                'headers' => self::parseHeaders($headers),
                'body' => $response !== false ? $response : '',
            ]);
        });

        return yield $deferred;
    }

    public static function tcpConnect(string $host, int $port, float $timeout = 5.0): \Generator {
        $deferred = new Deferred();

        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
        );

        if (!$socket) {
            $deferred->resolve(null);
            return yield $deferred;
        }

        stream_set_blocking($socket, false);

        self::$loop?->addWriter($socket, function ($s) use ($deferred, $socket) {
            self::$loop?->removeWriter($socket);
            $deferred->resolve($socket);
        });

        return yield $deferred;
    }

    public static function sleep(float $seconds): \Generator {
        return yield Coroutine::sleep($seconds);
    }

    private static function buildHeaders(array $headers): string {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        return implode("\r\n", $lines);
    }

    private static function parseHeaders(array $raw): array {
        $headers = [];
        foreach ($raw as $line) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return $headers;
    }
}

class AsyncDatabase {
    private static ?EventLoop $loop = null;
    private static array $config = [];
    private static string $connection = 'default';

    public static function setLoop(EventLoop $loop): void {
        self::$loop = $loop;
        if (self::$config !== []) {
            $driver = DB::connection(self::$connection);
            if ($driver instanceof \Nexph\Database\Drivers\AsyncDriverInterface) {
                $driver->attachLoop($loop);
            }
        }
    }

    public static function connect(array $config): void {
        self::close();
        self::$config = $config;
        self::$connection = (string) ($config['connection'] ?? 'default');
        $driver = DB::connect($config, self::$connection);
        if (self::$loop && $driver instanceof \Nexph\Database\Drivers\AsyncDriverInterface) {
            $driver->attachLoop(self::$loop);
        }
    }

    public static function query(string $sql, array $params = []): \Generator {
        $deferred = new Deferred();

        if (!self::$config) {
            $deferred->resolve(['error' => 'Database not connected']);
            return yield $deferred;
        }

        try {
            $driver = DB::connection(self::$connection);
            if ($driver instanceof \Nexph\Database\Drivers\AsyncDriverInterface) {
                $query = self::returnsRows($sql)
                    ? $driver->queryAsync($sql, $params)
                    : $driver->executeAsync($sql, $params);
                $query->rewind();
                $query->current()->then(function ($result) use ($sql, $deferred) {
                    self::resolveQuery($deferred, $sql, $result);
                });
            } else {
                self::resolveQuery($deferred, $sql, DB::result($sql, $params, self::$connection));
            }
        } catch (\Throwable $e) {
            $deferred->resolve(['error' => $e->getMessage()]);
        }

        return yield $deferred;
    }

    public static function insert(string $table, array $data): \Generator {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

        $deferred = new Deferred();
        if (!self::$config) {
            $deferred->resolve(['error' => 'Database not connected']);
            return yield $deferred;
        }

        try {
            $driver = DB::connection(self::$connection);
            if ($driver instanceof \Nexph\Database\Drivers\AsyncDriverInterface) {
                $query = $driver->executeAsync($sql, array_values($data));
                $query->rewind();
                $query->current()->then(function ($result) use ($deferred) {
                    if ($result instanceof \Nexph\Database\Drivers\DriverResult) {
                        $deferred->resolve($result->insertId);
                        return;
                    }
                    $deferred->resolve($result);
                });
            } else {
                $result = DB::result($sql, array_values($data), self::$connection);
                $deferred->resolve($result->insertId ?? DB::lastInsertId(self::$connection));
            }
        } catch (\Throwable $e) {
            $deferred->resolve(['error' => $e->getMessage()]);
        }

        return yield $deferred;
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): \Generator {
        $set = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        return yield from self::query($sql, array_merge(array_values($data), $whereParams));
    }

    public static function delete(string $table, string $where, array $params = []): \Generator {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return yield from self::query($sql, $params);
    }

    public static function stats(): array {
        $stats = DB::stats();
        return [
            'connected' => self::$config !== [],
            'driver' => $stats['driver'] ?? (self::$config['driver'] ?? null),
            'engine' => $stats['engine'] ?? null,
            'async' => (bool) ($stats['async'] ?? false),
            'in_flight' => (int) ($stats['in_flight'] ?? 0),
            'pool' => [
                'idle' => self::$config !== [] ? 1 : 0,
                'max' => 1,
            ],
            'statements' => [
                'cached' => (int) ($stats['statements_cached'] ?? 0),
                'max' => (int) ($stats['statement_cache_size'] ?? 0),
            ],
            'queries' => (int) ($stats['queries'] ?? 0),
            'writes' => (int) ($stats['writes'] ?? 0),
            'errors' => (int) ($stats['errors'] ?? 0),
            'avg_ms' => (float) ($stats['avg_ms'] ?? 0.0),
            'max_ms' => (float) ($stats['max_ms'] ?? 0.0),
            'slow_queries' => (int) ($stats['slow_queries'] ?? 0),
            'slow_query_ms' => (int) ($stats['slow_query_ms'] ?? 100),
            'pool_hits' => 0,
            'pool_misses' => self::$config !== [] ? 1 : 0,
            'statement_hits' => (int) ($stats['statement_hits'] ?? 0),
            'statement_misses' => (int) ($stats['statement_misses'] ?? 0),
            'transactions' => (int) ($stats['transactions'] ?? 0),
            'rollbacks' => (int) ($stats['rollbacks'] ?? 0),
        ];
    }

    public static function close(): void {
        if (self::$config !== []) {
            DB::disconnect(self::$connection);
        }
        self::$config = [];
    }

    private static function returnsRows(string $sql): bool {
        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');
        return in_array($verb, ['SELECT', 'PRAGMA', 'WITH', 'EXPLAIN', 'SHOW', 'DESCRIBE'], true);
    }

    private static function resolveQuery(Deferred $deferred, string $sql, mixed $result): void {
        if ($result instanceof \Nexph\Database\Drivers\DriverResult) {
            $deferred->resolve(self::returnsRows($sql) ? $result->rows : $result->affectedRows);
            return;
        }
        $deferred->resolve($result);
    }
}
