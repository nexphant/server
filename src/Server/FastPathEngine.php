<?php

namespace Nexph\Server\Server;

class FastPathEngine {
    private array $routes = [];
    private array $responses = [];
    private array $startLines = [];
    private array $startLineLengths = [];
    private array $headerCache = [];
    private int $cacheSize = 0;
    private const MAX_CACHE = 4096;
    private const CACHE_RETAIN = 2048;
    private const MAX_HEADER_LEN = 2048;

    public function register(string $method, string $path, string $rawResponse): void {
        $key = "$method $path";
        $this->routes[$key] = $rawResponse;
        $start = "$method $path HTTP/1.1\r\n";
        $this->startLines[$start] = $key;
        $this->startLineLengths[$start] = strlen($start);
        
        $this->responses[$key][1] = str_replace(
            "Connection: close",
            "Connection: keep-alive",
            $rawResponse
        );
        
        $this->responses[$key][0] = str_replace(
            "Connection: keep-alive",
            "Connection: close",
            $rawResponse
        );
    }

    public function hasRoutes(): bool {
        return $this->routes !== [];
    }

    public function matchExact(string $buffer): ?array {
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }

        $headerLen = $headerEnd + 4;
        if ($headerLen <= self::MAX_HEADER_LEN) {
            $header = substr($buffer, 0, $headerLen);
            if (isset($this->headerCache[$header])) {
                return $this->headerCache[$header];
            }
        } else {
            $header = null;
        }

        foreach ($this->startLines as $start => $key) {
            if (strncmp($buffer, $start, $this->startLineLengths[$start]) === 0) {
                $result = [
                    'key' => $key,
                    'keep_alive' => stripos($buffer, "Connection: close") === false,
                    'consumed' => $headerLen,
                ];
                if ($header !== null) {
                    $this->rememberHeader($header, $result);
                }
                return $result;
            }
        }

        $sp1 = strpos($buffer, ' ');
        if ($sp1 === false || $sp1 > 16) {
            return null;
        }

        $sp2 = strpos($buffer, ' ', $sp1 + 1);
        if ($sp2 === false || $sp2 > 128) {
            return null;
        }

        $uriStart = $sp1 + 1;
        $uriLen = $sp2 - $uriStart;
        $queryPos = strpos($buffer, '?', $uriStart);
        if ($uriLen <= 0 || ($queryPos !== false && $queryPos < $sp2)) {
            return null;
        }

        $key = substr($buffer, 0, $sp1) . ' ' . substr($buffer, $uriStart, $uriLen);
        
        if (!isset($this->routes[$key])) {
            return null;
        }

        $keepAlive = stripos($buffer, "Connection: keep-alive") !== false 
                  || stripos($buffer, "Connection: close") === false;

        $result = [
            'key' => $key,
            'keep_alive' => $keepAlive,
            'consumed' => $headerLen,
        ];

        if ($header !== null) {
            $this->rememberHeader($header, $result);
        }

        return $result;
    }

    private function rememberHeader(string $header, array $result): void {
        if ($this->cacheSize >= self::MAX_CACHE) {
            $this->headerCache = array_slice($this->headerCache, -self::CACHE_RETAIN, null, true);
            $this->cacheSize = self::CACHE_RETAIN;
        }
        $this->headerCache[$header] = $result;
        $this->cacheSize++;
    }

    public function match(string $buffer): ?array {
        return $this->matchExact($buffer);
    }

    public function getResponse(string $key, bool $keepAlive): string {
        return $this->responses[$key][$keepAlive ? 1 : 0] ?? $this->routes[$key] ?? '';
    }
}
