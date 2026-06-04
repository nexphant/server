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
