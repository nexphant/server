<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Server;

class HttpParser
{
    private static array $methodMap = [
        'G' => 'GET',
        'P' => 'POST',
        'D' => 'DELETE',
        'H' => 'HEAD',
        'O' => 'OPTIONS',
    ];

    public static function parseRequest(string $raw): ?array
    {
        $headerEnd = strpos($raw, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }

        // Parse request line without regex
        $lineEnd = strpos($raw, "\r\n");
        $line = substr($raw, 0, $lineEnd);

        $sp1 = strpos($line, ' ');
        if ($sp1 === false) {
            return null;
        }
        $sp2 = strpos($line, ' ', $sp1 + 1);
        if ($sp2 === false) {
            return null;
        }

        $method = substr($line, 0, $sp1);
        // Validate method fast
        $first = $method[0];
        if ($first === 'P') {
            if ($method !== 'POST' && $method !== 'PUT' && $method !== 'PATCH') {
                return null;
            }
        } elseif (!isset(self::$methodMap[$first]) || (self::$methodMap[$first] !== $method && $method !== 'PUT' && $method !== 'PATCH')) {
            if ($method !== 'GET' && $method !== 'POST' && $method !== 'PUT' && $method !== 'PATCH' && $method !== 'DELETE' && $method !== 'HEAD' && $method !== 'OPTIONS') {
                return null;
            }
        }

        $uri = substr($line, $sp1 + 1, $sp2 - $sp1 - 1);
        if ($uri === '' || str_contains($uri, "\0") || str_contains($uri, "\r") || str_contains($uri, "\n")) {
            return null;
        }

        // Parse path and query
        $qPos = strpos($uri, '?');
        if ($qPos !== false) {
            $path = substr($uri, 0, $qPos);
            $queryString = substr($uri, $qPos + 1);
        } else {
            $path = $uri;
            $queryString = '';
        }

        // Parse headers inline
        $headers = [];
        $contentLength = 0;
        $contentType = '';
        $cookie = '';
        $pos = $lineEnd + 2;
        while ($pos < $headerEnd) {
            $next = strpos($raw, "\r\n", $pos);
            if ($next === false) {
                $next = $headerEnd;
            }
            $colon = strpos($raw, ':', $pos);
            if ($colon !== false && $colon < $next) {
                $key = strtolower(substr($raw, $pos, $colon - $pos));
                if (!self::validHeaderName($key)) {
                    return null;
                }
                $value = ltrim(substr($raw, $colon + 1, $next - $colon - 1));
                if (str_contains($value, "\r") || str_contains($value, "\n")) {
                    return null;
                }
                $headers[$key] = $value;
                // Cache hot headers
                if ($key === 'content-length') {
                    if (!preg_match('/^\d+$/', $value)) {
                        return null;
                    }
                    $contentLength = (int) $value;
                } elseif ($key === 'content-type') {
                    $contentType = $value;
                } elseif ($key === 'cookie') {
                    $cookie = $value;
                }
            } elseif ($next > $pos) {
                return null;
            }
            $pos = $next + 2;
        }

        // Check body completeness
        $body = '';
        if ($contentLength > 0) {
            $bodyStart = $headerEnd + 4;
            $available = strlen($raw) - $bodyStart;
            if ($available < $contentLength) {
                return null;
            }
            $body = substr($raw, $bodyStart, $contentLength);
        }

        $query = [];
        $parsedBody = [];
        $cookies = [];

        return [
            'method' => $method,
            'uri' => $uri,
            'path' => $path,
            'query_string' => $queryString,
            'query' => $query,
            'headers' => $headers,
            'body' => $body,
            'parsed_body' => $parsedBody,
            'cookies' => $cookies,
            'raw_length' => $headerEnd + 4 + $contentLength,
        ];
    }

    public static function buildResponse(int $status, array $headers, string $body): string
    {
        static $statusTexts = [
        200 => 'OK',
        101 => 'Switching Protocols',
        201 => 'Created',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        413 => 'Payload Too Large',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        ];

        $statusText = $statusTexts[$status] ?? 'Unknown';

        $contentType = strtolower((string) ($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
        $streaming = str_starts_with($contentType, 'text/event-stream');
        if (!$streaming && $status !== 101 && $status !== 204 && $status !== 304) {
            $headers['Content-Length'] = strlen($body);
        }
        $headers['Connection'] = $headers['Connection'] ?? 'keep-alive';

        // Build with single string concat
        $head = "HTTP/1.1 {$status} {$statusText}\r\n";
        foreach ($headers as $key => $value) {
            if (!self::validHeaderName((string) $key)) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $v) {
                    $v = self::sanitizeHeaderValue((string) $v);
                    $head .= "{$key}: {$v}\r\n";
                }
            } else {
                $value = self::sanitizeHeaderValue((string) $value);
                $head .= "{$key}: {$value}\r\n";
            }
        }

        return $head . "\r\n" . $body;
    }

    public static function validHeaderName(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) === 1;
    }

    public static function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
