<?php

namespace Nexph\Server;

class RawResponse {
    public function __construct(public readonly string $http) {}

    public static function json(string $json, int $status = 200): self {
        static $cache = [];
        $key = $status . ':' . $json;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $len = strlen($json);
        $statusText = $status === 200 ? 'OK' : 'Unknown';
        $date = gmdate('D, d M Y H:i:s T');
        $http = "HTTP/1.1 {$status} {$statusText}\r\nContent-Type: application/json\r\nContent-Length: {$len}\r\nDate: {$date}\r\nServer: Nexph/1.0\r\nConnection: keep-alive\r\n\r\n{$json}";
        $response = new self($http);
        if (count($cache) < 256) {
            $cache[$key] = $response;
        }
        return $response;
    }
}
