<?php

namespace Nexph\Server;

class FastPathRegistry {
    private array $map = [];

    public function register(string $method, string $path, string $rawResponse): void {
        if (!isset($this->map[$method])) {
            $this->map[$method] = [];
        }
        $this->map[$method][$path] = $rawResponse;
    }

    public function get(string $method, string $path): ?string {
        return $this->map[$method][$path] ?? null;
    }

    public function has(string $method, string $path): bool {
        return isset($this->map[$method][$path]);
    }
}
