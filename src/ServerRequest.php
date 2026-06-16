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

class ServerRequest extends \Nexphant\Request
{
    private ?Server\Connection $connection = null;
    private array $attributes = [];
    private bool $queryParsed = false;
    private bool $cookiesParsed = false;
    private bool $bodyParsed = false;
    private string $rawCookie = '';
    private string $contentType = '';

    public function __construct(?array $parsed = null, ?Server\Connection $conn = null)
    {
        if ($parsed !== null && $conn !== null) {
            $this->hydrate($parsed, $conn);
        }
    }

    public function hydrate(array $parsed, Server\Connection $conn): void
    {
        $this->connection = $conn;
        $this->method = $parsed['method'];
        $this->uri = $parsed['uri'];
        $this->path = $parsed['path'];
        $this->queryString = $parsed['query_string'];
        $this->query = [];
        $this->headers = $parsed['headers'];
        $this->body = $parsed['body'];
        $this->parsedBody = [];
        $this->cookies = [];
        $this->remoteAddr = $conn->getRemoteAddr();
        $this->remotePort = $conn->getRemotePort();
        $this->time = microtime(true);
        $this->attributes = [];
        $this->queryParsed = $parsed['query_string'] === '';
        $this->cookiesParsed = !isset($parsed['headers']['cookie']);
        $this->bodyParsed = $parsed['body'] === '';
        $this->rawCookie = $this->headers['cookie'] ?? '';
        $this->contentType = $this->headers['content-type'] ?? '';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $name, mixed $default = null): mixed
    {
        if (!$this->queryParsed && $this->queryString !== '') {
            parse_str($this->queryString, $this->query);
            $this->queryParsed = true;
        }
        return $this->query[$name] ?? $default;
    }

    public function post(string $name, mixed $default = null): mixed
    {
        if (!$this->bodyParsed && $this->body !== '' && $this->contentType !== '') {
            if (str_contains($this->contentType, 'application/json')) {
                $this->parsedBody = json_decode($this->body, true) ?? [];
            } elseif (str_contains($this->contentType, 'application/x-www-form-urlencoded')) {
                parse_str($this->body, $this->parsedBody);
            }
            $this->bodyParsed = true;
        }
        return $this->parsedBody[$name] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        if (!$this->cookiesParsed && $this->rawCookie !== '') {
            $pairs = explode(';', $this->rawCookie);
            foreach ($pairs as $pair) {
                $pair = trim($pair);
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $this->cookies[substr($pair, 0, $eq)] = urldecode(substr($pair, $eq + 1));
                }
            }
            $this->cookiesParsed = true;
        }
        return $this->cookies[$name] ?? $default;
    }

    public function input(string $name, mixed $default = null): mixed
    {
        return $this->parsedBody[$name] ?? $this->query[$name] ?? $default;
    }

    public function all(): array
    {
        if (!$this->queryParsed && $this->queryString !== '') {
            parse_str($this->queryString, $this->query);
            $this->queryParsed = true;
        }
        if (!$this->bodyParsed && $this->body !== '' && $this->contentType !== '') {
            if (str_contains($this->contentType, 'application/json')) {
                $this->parsedBody = json_decode($this->body, true) ?? [];
            } elseif (str_contains($this->contentType, 'application/x-www-form-urlencoded')) {
                parse_str($this->body, $this->parsedBody);
            }
            $this->bodyParsed = true;
        }
        return array_merge($this->query, $this->parsedBody);
    }

    public function json(): array
    {
        if (!$this->bodyParsed && $this->body !== '' && $this->contentType !== '') {
            if (str_contains($this->contentType, 'application/json')) {
                $this->parsedBody = json_decode($this->body, true) ?? [];
            } elseif (str_contains($this->contentType, 'application/x-www-form-urlencoded')) {
                parse_str($this->body, $this->parsedBody);
            }
            $this->bodyParsed = true;
        }
        return $this->parsedBody;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('content-type', ''), 'application/json');
    }

    public function isMethod(string $method): bool
    {
        return strcasecmp($this->method, $method) === 0;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getConnection(): Connection
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Request has no active connection');
        }
        return $this->connection;
    }

    public function wantsKeepAlive(): bool
    {
        $connection = strtolower($this->header('connection', 'keep-alive'));
        return $connection !== 'close';
    }

    public function isClean(): bool
    {
        return $this->connection === null &&
            $this->method === '' &&
            $this->attributes === [];
    }

    public function reset(): void
    {
        $this->method = '';
        $this->uri = '';
        $this->path = '';
        $this->queryString = '';
        $this->query = [];
        $this->headers = [];
        $this->body = '';
        $this->parsedBody = [];
        $this->cookies = [];
        $this->remoteAddr = '';
        $this->remotePort = 0;
        $this->time = 0.0;
        $this->connection = null;
        $this->attributes = [];
        $this->queryParsed = false;
        $this->cookiesParsed = false;
        $this->bodyParsed = false;
        $this->rawCookie = '';
        $this->contentType = '';
    }
}

