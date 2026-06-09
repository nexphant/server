<?php

namespace Nexph\Server\Server;

class FastPathEngine {
    private array $routes = [];
    private array $prebuilt = [];
    private array $exactCache = [];

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
        if (isset($this->exactCache[$buffer])) {
            return $this->exactCache[$buffer];
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

        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }

        $keepAlive = stripos($buffer, "Connection: keep-alive") !== false 
                  || stripos($buffer, "Connection: close") === false;

        $result = [
            'key' => $key,
            'keep_alive' => $keepAlive,
            'consumed' => $headerEnd + 4,
        ];

        if (strlen($buffer) < 512) {
            $this->exactCache[$buffer] = $result;
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
