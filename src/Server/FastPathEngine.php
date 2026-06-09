<?php

namespace Nexph\Server\Server;

class FastPathEngine {
    private array $routes = [];
    private array $prebuilt = [];
    private array $headerCache = [];
    private int $cacheSize = 0;
    private const MAX_CACHE = 512;
    private const MAX_HEADER_LEN = 2048;

    public function register(string $method, string $path, string $rawResponse): void {
        $key = "$method $path";
        $this->routes[$key] = $rawResponse;
        
        $this->prebuilt[$key . ' keep-alive'] = str_replace(
            "Connection: close",
            "Connection: keep-alive",
            $rawResponse
        );
        
        $this->prebuilt[$key . ' close'] = str_replace(
            "Connection: keep-alive",
            "Connection: close",
            $rawResponse
        );
    }

    public function matchExact(string $buffer): ?array {
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }

        $headerLen = $headerEnd + 4;
        $header = substr($buffer, 0, $headerLen);

        if ($headerLen <= self::MAX_HEADER_LEN && isset($this->headerCache[$header])) {
            return $this->headerCache[$header];
        }

        $end = strpos($buffer, "\r\n");
        if ($end === false || $end > 128) {
            return null;
        }

        $line = substr($buffer, 0, $end);
        $parts = explode(' ', $line, 3);
        
        if (count($parts) < 3) {
            return null;
        }

        [$method, $uri] = $parts;
        
        if (strpos($uri, '?') !== false) {
            return null;
        }

        $key = "$method $uri";
        
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

        if ($headerLen <= self::MAX_HEADER_LEN) {
            if ($this->cacheSize >= self::MAX_CACHE) {
                $this->headerCache = array_slice($this->headerCache, -256, null, true);
                $this->cacheSize = 256;
            }
            $this->headerCache[$header] = $result;
            $this->cacheSize++;
        }

        return $result;
    }

    public function match(string $buffer): ?array {
        return $this->matchExact($buffer);
    }

    public function getResponse(string $key, bool $keepAlive): string {
        $variant = $key . ($keepAlive ? ' keep-alive' : ' close');
        return $this->prebuilt[$variant] ?? $this->routes[$key] ?? '';
    }
}
