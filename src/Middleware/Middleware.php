<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Server\Middleware;

use Nexph\Server\ServerRequest;
use Nexph\Server\ServerResponse;
use Nexph\Server\Coroutine;

class Cors {
    private array $options;

    public function __construct(array $options = []) {
        $this->options = array_merge([
            'origin' => '*',
            'methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'headers' => 'Content-Type, Authorization, X-Requested-With',
            'credentials' => false,
            'max_age' => 86400,
        ], $options);
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): bool {
        $origin = $this->options['origin'];
        if (is_array($origin)) {
            $requestOrigin = $request->header('origin', '');
            $origin = in_array($requestOrigin, $origin) ? $requestOrigin : $origin[0];
        }

        $response->header('Access-Control-Allow-Origin', $origin);
        $response->header('Access-Control-Allow-Methods', $this->options['methods']);
        $response->header('Access-Control-Allow-Headers', $this->options['headers']);

        if ($this->options['credentials']) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        if ($request->method === 'OPTIONS') {
            $response->header('Access-Control-Max-Age', (string) $this->options['max_age']);
            $response->status(204)->body('');
            return false;
        }

        return true;
    }
}

class RateLimit {
    private array $buckets = [];
    private int $maxRequests;
    private int $window;

    public function __construct(int $maxRequests = 100, int $window = 60) {
        $this->maxRequests = $maxRequests;
        $this->window = $window;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): bool {
        $key = $request->remoteAddr;
        $now = time();

        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = ['count' => 0, 'reset' => $now + $this->window];
        }

        $bucket = &$this->buckets[$key];

        if ($now > $bucket['reset']) {
            $bucket['count'] = 0;
            $bucket['reset'] = $now + $this->window;
        }

        $bucket['count']++;
        $remaining = max(0, $this->maxRequests - $bucket['count']);

        $response->header('X-RateLimit-Limit', (string) $this->maxRequests);
        $response->header('X-RateLimit-Remaining', (string) $remaining);
        $response->header('X-RateLimit-Reset', (string) $bucket['reset']);

        if ($bucket['count'] > $this->maxRequests) {
            $response->header('Retry-After', (string) ($bucket['reset'] - $now));
            $response->json(['error' => 'Too Many Requests'], 429);
            return false;
        }

        return true;
    }

    public function cleanup(): void {
        $now = time();
        foreach ($this->buckets as $key => $bucket) {
            if ($now > $bucket['reset'] + 60) {
                unset($this->buckets[$key]);
            }
        }
    }
}

class Logger {
    private string $format;

    public function __construct(string $format = 'combined') {
        $this->format = $format;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): void {
        $start = $request->time;

        register_shutdown_function(function () use ($request, $response, $start) {
            $duration = (microtime(true) - $start) * 1000;
            $this->log($request, $response, $duration);
        });
    }

    private function log(ServerRequest $request, ServerResponse $response, float $duration): void {
        $line = sprintf(
            '%s - - [%s] "%s %s" %d %d %.2fms',
            $request->remoteAddr,
            date('d/M/Y:H:i:s O'),
            $request->method,
            $request->uri,
            $response->getStatus(),
            strlen($response->getBody()),
            $duration
        );

        echo $line . "\n";
    }
}

class Security {
    public function __invoke(ServerRequest $request, ServerResponse $response): void {
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Frame-Options', 'DENY');
        $response->header('X-XSS-Protection', '1; mode=block');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}

class Compress {
    private int $minSize;

    public function __construct(int $minSize = 1024) {
        $this->minSize = $minSize;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): void {
        // Check if client accepts gzip
        $acceptEncoding = $request->header('accept-encoding', '');
        if (!str_contains($acceptEncoding, 'gzip')) {
            return;
        }

        // Will be applied after response is built
        // This is a placeholder - actual compression needs response interception
    }
}

class BodyParser {
    private int $maxSize;

    public function __construct(int $maxSize = 10 * 1024 * 1024) {
        $this->maxSize = $maxSize;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): void {
        if (strlen($request->body) > $this->maxSize) {
            $response->json(['error' => 'Request body too large'], 413);
        }
    }
}

class Timeout {
    private float $seconds;

    public function __construct(float $seconds = 30.0) {
        $this->seconds = $seconds;
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): \Generator {
        $elapsed = microtime(true) - $request->time;
        if ($elapsed > $this->seconds) {
            $response->json(['error' => 'Request timeout'], 408);
        }
        yield;
    }
}
