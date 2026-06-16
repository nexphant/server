<?php
namespace nexphant;

abstract class Request implements \nexphant\Server\Resettable, \nexphant\Server\Cleanable {
    public string $method = '';
    public string $uri = '';
    public string $path = '';
    public string $queryString = '';
    public array $query = [];
    public array $headers = [];
    public string $body = '';
    public array $parsedBody = [];
    public array $cookies = [];
    public string $remoteAddr = '';
    public int $remotePort = 0;
    public float $time = 0.0;

    abstract public function header(string $name, ?string $default = null): ?string;
    abstract public function query(string $name, mixed $default = null): mixed;
    abstract public function post(string $name, mixed $default = null): mixed;
    abstract public function cookie(string $name, ?string $default = null): ?string;
    abstract public function input(string $name, mixed $default = null): mixed;
    abstract public function all(): array;
    abstract public function json(): array;
    abstract public function isJson(): bool;
    abstract public function isMethod(string $method): bool;
    abstract public function setAttribute(string $name, mixed $value): void;
    abstract public function getAttribute(string $name, mixed $default = null): mixed;
    abstract public function getAttributes(): array;
}
