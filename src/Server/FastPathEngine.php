<?php

namespace nexphant\Server\Server;

use nexphant\Server\Server\Native\NativeOps;
use nexphant\Server\Server\Native\PhpNativeOps;

class FastPathEngine {
    private array $routes = [];
    private array $responses = [];
    private array $startLines = [];
    private array $startLineLengths = [];
    private string $primaryStartLine = '';
    private string $primaryKey = '';
    private int $primaryStartLineLength = 0;
    private string $primaryKeepAliveResponse = '';
    private string $primaryCloseResponse = '';
    private array $headerCache = [];
    private int $cacheSize = 0;
    private const MAX_CACHE = 4096;
    private const CACHE_RETAIN = 2048;
    private const MAX_HEADER_LEN = 2048;

    public function __construct(private ?NativeOps $native = null) {
        $this->native ??= new PhpNativeOps();
    }

    public function register(string $method, string $path, string $rawResponse): void {
        $key = "$method $path";
        $this->routes[$key] = $rawResponse;
        $start = "$method $path HTTP/1.1\r\n";
        $this->startLines[$start] = $key;
        $this->startLineLengths[$start] = strlen($start);
        if ($this->primaryStartLine === '') {
            $this->primaryStartLine = $start;
            $this->primaryKey = $key;
            $this->primaryStartLineLength = $this->startLineLengths[$start];
        }
        
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
        if ($this->primaryKey === $key) {
            $this->primaryKeepAliveResponse = $this->responses[$key][1];
            $this->primaryCloseResponse = $this->responses[$key][0];
        }
    }

    public function hasRoutes(): bool {
        return $this->routes !== [];
    }

    public function native(): NativeOps {
        return $this->native;
    }

    public function matchPrimary(string $buffer): int {
        if ($this->primaryStartLine === '') {
            return 0;
        }

        if (strncmp($buffer, $this->primaryStartLine, $this->primaryStartLineLength) !== 0) {
            return 0;
        }

        $headerEnd = $this->native->findHeaderEnd($buffer, $this->primaryStartLineLength);
        return $headerEnd === false ? 0 : $headerEnd + 4;
    }

    public function getPrimaryResponse(bool $keepAlive): string {
        return $keepAlive ? $this->primaryKeepAliveResponse : $this->primaryCloseResponse;
    }

    public function primaryStartLine(): string {
        return $this->primaryStartLine;
    }

    public function primaryStartLineLength(): int {
        return $this->primaryStartLineLength;
    }

    public function primaryKeepAliveResponse(): string {
        return $this->primaryKeepAliveResponse;
    }

    public function primaryCloseResponse(): string {
        return $this->primaryCloseResponse;
    }

    public function matchExact(string $buffer): ?array {
        $headerEnd = $this->native->findHeaderEnd($buffer);
        if ($headerEnd === false) {
            return null;
        }

        $headerLen = $headerEnd + 4;
        if ($this->primaryStartLine !== '' && strncmp($buffer, $this->primaryStartLine, $this->primaryStartLineLength) === 0) {
            return [
                'key' => $this->primaryKey,
                'keep_alive' => !$this->native->hasConnectionClose($buffer),
                'consumed' => $headerLen,
            ];
        }

        foreach ($this->startLines as $start => $key) {
            if ($start === $this->primaryStartLine) {
                continue;
            }
            if (strncmp($buffer, $start, $this->startLineLengths[$start]) === 0) {
                return [
                    'key' => $key,
                    'keep_alive' => !$this->native->hasConnectionClose($buffer),
                    'consumed' => $headerLen,
                ];
            }
        }

        if ($headerLen <= self::MAX_HEADER_LEN) {
            $header = substr($buffer, 0, $headerLen);
            if (isset($this->headerCache[$header])) {
                return $this->headerCache[$header];
            }
        } else {
            $header = null;
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
                  || !$this->native->hasConnectionClose($buffer);

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
