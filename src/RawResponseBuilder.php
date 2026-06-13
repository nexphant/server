<?php

namespace Nexph\Server;

class RawResponseBuilder {
    public static function build(int $status, array $headers, string $body): string {
        static $statusTexts = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ];
        $statusText = $statusTexts[$status] ?? 'OK';
        $headers['Content-Length'] = strlen($body);
        $headers['Connection'] = $headers['Connection'] ?? 'keep-alive';
        $head = "HTTP/1.1 {$status} {$statusText}\r\n";
        foreach ($headers as $key => $value) {
            if (!HttpParser::validHeaderName((string) $key)) {
                continue;
            }
            $value = HttpParser::sanitizeHeaderValue((string) $value);
            $head .= "{$key}: {$value}\r\n";
        }
        return $head . "\r\n" . $body;
    }

    public static function json(int $status, array|string $payload, array $headers = []): string {
        $json = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers['Content-Type'] = 'application/json';
        return self::build($status, $headers, $json);
    }

    public static function text(int $status, string $body, array $headers = []): string {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'text/plain; charset=utf-8';
        return self::build($status, $headers, $body);
    }

    public static function raw(int $status, string $body, array $headers = []): string {
        return self::build($status, $headers, $body);
    }
}
