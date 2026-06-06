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

use Nexph\Runtime\JsonSerializer;

class ServerResponse extends \Nexph\Response {
    public function status(int $code): self {
        $this->status = $code;
        return $this;
    }

    public function header(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }

    public function headers(array $headers): self {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    public function cookie(string $name, string $value, array $options = []): self {
        $cookie = urlencode($name) . '=' . urlencode($value);

        if (isset($options['expires'])) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s T', $options['expires']);
        }
        if (isset($options['max_age'])) {
            $cookie .= '; Max-Age=' . $options['max_age'];
        }
        if (isset($options['path'])) {
            $cookie .= '; Path=' . $options['path'];
        }
        if (isset($options['domain'])) {
            $cookie .= '; Domain=' . $options['domain'];
        }
        if (!empty($options['secure'])) {
            $cookie .= '; Secure';
        }
        if (!empty($options['httponly'])) {
            $cookie .= '; HttpOnly';
        }
        if (isset($options['samesite'])) {
            $cookie .= '; SameSite=' . $options['samesite'];
        }

        $this->cookies[] = $cookie;
        return $this;
    }

    public function body(string $body): self {
        $this->body = $body;
        return $this;
    }

    public function rawHttp(string $response): self {
        $this->raw = $response;
        return $this;
    }

    public function cacheAs(string $key): self {
        $this->cacheKey = $key;
        return $this;
    }

    public function json(mixed $data, int $status = 200): self {
        $this->status = $status;
        $this->headers['Content-Type'] = 'application/json';
        $this->body = JsonSerializer::encode($data);
        return $this;
    }

    public function html(string $html, int $status = 200): self {
        $this->status = $status;
        $this->headers['Content-Type'] = 'text/html; charset=utf-8';
        $this->body = $html;
        return $this;
    }

    public function text(string $text, int $status = 200): self {
        $this->status = $status;
        $this->headers['Content-Type'] = 'text/plain; charset=utf-8';
        $this->body = $text;
        return $this;
    }

    public function redirect(string $url, int $status = 302): self {
        $this->status = $status;
        $this->headers['Location'] = $url;
        return $this;
    }

    public function notFound(string $message = 'Not Found'): self {
        return $this->json(['error' => $message], 404);
    }

    public function error(string $message = 'Internal Server Error', int $status = 500): self {
        return $this->json(['error' => $message], $status);
    }

    public function build(bool $keepAlive = true): string {
        if ($this->raw !== null) {
            $this->sent = true;
            return $this->raw;
        }

        if ($this->status === 200 && empty($this->cookies) && isset($this->headers['Content-Type']) && $this->headers['Content-Type'] === 'application/json' && count($this->headers) === 1) {
            static $jsonPrefix200KA = "";
            static $jsonPrefix200Close = "";
            static $jsonDateSecond2 = 0;
            $now2 = time();
            if ($now2 !== $jsonDateSecond2) {
                $jsonDateSecond2 = $now2;
                $d = gmdate('D, d M Y H:i:s T', $now2);
                $jsonPrefix200KA = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nDate: {$d}\r\nServer: Nexph/1.0\r\nConnection: keep-alive\r\nContent-Length: ";
                $jsonPrefix200Close = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nDate: {$d}\r\nServer: Nexph/1.0\r\nConnection: close\r\nContent-Length: ";
            }
            $this->sent = true;
            $prefix = $keepAlive ? $jsonPrefix200KA : $jsonPrefix200Close;
            return $prefix . strlen($this->body) . "\r\n\r\n" . $this->body;
        }

        $headers = $this->headers;
        static $date = '';
        static $dateSecond = 0;
        $now = time();
        if ($now !== $dateSecond) {
            $dateSecond = $now;
            $date = gmdate('D, d M Y H:i:s T', $now);
        }
        $headers['Date'] = $date;
        $headers['Server'] = 'Nexph/1.0';
        $headers['Connection'] = $headers['Connection'] ?? ($keepAlive ? 'keep-alive' : 'close');

        if (!empty($this->cookies)) {
            $headers['Set-Cookie'] = $this->cookies;
        }

        $this->sent = true;
        return HttpParser::buildResponse($this->status, $headers, $this->body);
    }

    public function isSent(): bool {
        return $this->sent;
    }

    public function markSent(): self {
        $this->sent = true;
        return $this;
    }

    public function getStatus(): int {
        return $this->status;
    }

    public function getBody(): string {
        return $this->body;
    }

    public function getCacheKey(): ?string {
        return $this->cacheKey;
    }

    public function isClean(): bool {
        return $this->status === 200 &&
            $this->headers === [] &&
            $this->body === '' &&
            $this->raw === null &&
            $this->cacheKey === null &&
            $this->sent === false &&
            $this->cookies === [];
    }

    public function reset(): void {
        $this->status = 200;
        $this->headers = [];
        $this->body = '';
        $this->raw = null;
        $this->cacheKey = null;
        $this->sent = false;
        $this->cookies = [];
    }
}
