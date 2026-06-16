<?php
namespace nexphant;

abstract class Response implements \nexphant\Server\Resettable, \nexphant\Server\Cleanable {
    protected int $status = 200;
    protected array $headers = [];
    protected string $body = '';
    protected ?string $raw = null;
    protected ?string $cacheKey = null;
    protected bool $sent = false;
    protected array $cookies = [];

    abstract public function status(int $code): self;
    abstract public function header(string $name, string $value): self;
    abstract public function headers(array $headers): self;
    abstract public function cookie(string $name, string $value, array $options = []): self;
    abstract public function body(string $body): self;
    abstract public function json(mixed $data, int $status = 200): self;
    abstract public function html(string $html, int $status = 200): self;
    abstract public function text(string $text, int $status = 200): self;
    abstract public function redirect(string $url, int $status = 302): self;
    abstract public function notFound(string $message = 'Not Found'): self;
    abstract public function error(string $message = 'Internal Server Error', int $status = 500): self;
    abstract public function isSent(): bool;
    abstract public function getStatus(): int;
    abstract public function getBody(): string;
}
