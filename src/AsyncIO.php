<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server;

use Nexphant\Database\DB;

class AsyncIO
{
    private static ?EventLoop $loop = null;

    public static function setLoop(EventLoop $loop): void
    {
        self::$loop = $loop;
    }

    public static function readFile(string $path): \Generator
    {
        $deferred = new Deferred();

        $realPath = realpath($path);
        if ($realPath === false || str_contains($path, '..') || str_contains($path, "\0")) {
            $deferred->resolve(null);
            return yield $deferred;
        }

        if (!file_exists($realPath)) {
            $deferred->resolve(null);
            return yield $deferred;
        }

        self::$loop?->defer(function () use ($realPath, $deferred) {
            $content = @file_get_contents($realPath);
            $deferred->resolve($content !== false ? $content : null);
        });

        return yield $deferred;
    }

    public static function writeFile(string $path, string $content): \Generator
    {
        $deferred = new Deferred();

        if (str_contains($path, '..') || str_contains($path, "\0")) {
            $deferred->resolve(false);
            return yield $deferred;
        }

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

    public static function httpRequest(string $method, string $url, array $options = []): \Generator
    {
        $deferred = new Deferred();

        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            $deferred->resolve(['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Invalid URL scheme']);
            return yield $deferred;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host && self::isPrivateOrLoopbackIP($host)) {
            $deferred->resolve(['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Private/loopback IPs not allowed']);
            return yield $deferred;
        }

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

    public static function tcpConnect(string $host, int $port, float $timeout = 5.0): \Generator
    {
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

    public static function sleep(float $seconds): \Generator
    {
        return yield Coroutine::sleep($seconds);
    }

    private static function buildHeaders(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        return implode("\r\n", $lines);
    }

    private static function parseHeaders(array $raw): array
    {
        $headers = [];
        foreach ($raw as $line) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return $headers;
    }

    private static function isPrivateOrLoopbackIP(string $host): bool
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip === '::1' || str_starts_with($ip, 'fe80:') || str_starts_with($ip, 'fc00:') || str_starts_with($ip, 'fd00:');
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return false;
        }
        $first = (int) $parts[0];
        $second = (int) $parts[1];
        return $first === 127 || $first === 10 || ($first === 172 && $second >= 16 && $second <= 31) || ($first === 192 && $second === 168);
    }
}

class AsyncDatabase
{
    private static ?EventLoop $loop = null;
    private static array $config = [];
    private static string $connection = 'default';

    public static function setLoop(EventLoop $loop): void
    {
        self::$loop = $loop;
        if (self::$config !== []) {
            $driver = DB::connection(self::$connection);
            if ($driver instanceof \Nexphant\Database\Drivers\AsyncDriverInterface) {
                $driver->attachLoop($loop);
            }
        }
    }

    public static function connect(array $config): void
    {
        self::close();
        self::$config = $config;
        self::$connection = (string) ($config['connection'] ?? 'default');
        $driver = DB::connect($config, self::$connection);
        if (self::$loop && $driver instanceof \Nexphant\Database\Drivers\AsyncDriverInterface) {
            $driver->attachLoop(self::$loop);
        }
    }

    public static function query(string $sql, array $params = []): \Generator
    {
        $deferred = new Deferred();

        if (!self::$config) {
            $deferred->resolve(['error' => 'Database not connected']);
            return yield $deferred;
        }

        try {
            $driver = DB::connection(self::$connection);
            if ($driver instanceof \Nexphant\Database\Drivers\AsyncDriverInterface) {
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

    public static function insert(string $table, array $data): \Generator
    {
        self::assertIdentifier($table);
        $columns = implode(', ', array_map([self::class, 'quoteId'], array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";

        $deferred = new Deferred();
        if (!self::$config) {
            $deferred->resolve(['error' => 'Database not connected']);
            return yield $deferred;
        }

        try {
            $driver = DB::connection(self::$connection);
            if ($driver instanceof \Nexphant\Database\Drivers\AsyncDriverInterface) {
                $query = $driver->executeAsync($sql, array_values($data));
                $query->rewind();
                $query->current()->then(function ($result) use ($deferred) {
                    if ($result instanceof \Nexphant\Database\Drivers\DriverResult) {
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

    public static function update(string $table, array $data, string $where, array $whereParams = []): \Generator
    {
        self::assertIdentifier($table);
        $set = implode(', ', array_map(fn($k) => self::quoteId($k) . " = ?", array_keys($data)));
        $sql = "UPDATE `{$table}` SET {$set} WHERE {$where}";
        return yield from self::query($sql, array_merge(array_values($data), $whereParams));
    }

    public static function delete(string $table, string $where, array $params = []): \Generator
    {
        self::assertIdentifier($table);
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        return yield from self::query($sql, $params);
    }

    public static function stats(): array
    {
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

    public static function close(): void
    {
        if (self::$config !== []) {
            DB::disconnect(self::$connection);
        }
        self::$config = [];
    }

    private static function returnsRows(string $sql): bool
    {
        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');
        return in_array($verb, ['SELECT', 'PRAGMA', 'WITH', 'EXPLAIN', 'SHOW', 'DESCRIBE'], true);
    }

    private static function resolveQuery(Deferred $deferred, string $sql, mixed $result): void
    {
        if ($result instanceof \Nexphant\Database\Drivers\DriverResult) {
            $deferred->resolve(self::returnsRows($sql) ? $result->rows : $result->affectedRows);
            return;
        }
        $deferred->resolve($result);
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid identifier: {$name}");
        }
    }

    private static function quoteId(string $name): string
    {
        self::assertIdentifier($name);
        return "`{$name}`";
    }
}
