<?php

namespace nexphant\Server;

class RawResponse {
    public function __construct(public readonly string $http) {}

    public static function json(string $json, int $status = 200, bool $keepAlive = true): self {
        static $cache = [];
        $key = $status . ':' . ($keepAlive ? '1' : '0') . ':' . $json;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $len = strlen($json);
        $statusText = $status === 200 ? 'OK' : ($status === 201 ? 'Created' : 'Unknown');
        $date = gmdate('D, d M Y H:i:s T');
        $conn = $keepAlive ? 'keep-alive' : 'close';
        $http = "HTTP/1.1 {$status} {$statusText}\r\nContent-Type: application/json\r\nContent-Length: {$len}\r\nDate: {$date}\r\nServer: nexphant/1.0\r\nConnection: {$conn}\r\n\r\n{$json}";
        $response = new self($http);
        if (count($cache) < 512) {
            $cache[$key] = $response;
        }
        return $response;
    }
}
