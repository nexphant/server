<?php

namespace Nexphant\Server\Server;

use Nexphant\Server\Server\Connection;

class KeepAlive {
    private int $timeout;
    private int $webSocketTimeout;
    private int $sseTimeout;
    private int $maxRequests;

    public function __construct(int $timeout, int $webSocketTimeout, int $sseTimeout, int $maxRequests) {
        $this->timeout = $timeout;
        $this->webSocketTimeout = $webSocketTimeout;
        $this->sseTimeout = $sseTimeout;
        $this->maxRequests = $maxRequests;
    }

    public function shouldKeepAlive(Connection $conn, bool $wantsKeepAlive, int $totalConnections, int $maxConnections, bool $draining, string $memoryPressure): bool {
        if ($draining || $memoryPressure !== 'normal') {
            return false;
        }
        if ($conn->isWebSocket() || $conn->isSse()) {
            return true;
        }
        return $wantsKeepAlive
            && $conn->getRequestCount() < $this->maxRequests
            && $totalConnections < $maxConnections * 0.8;
    }

    public function isTimedOut(Connection $conn, float $now): bool {
        if ($conn->isWebSocket()) {
            return ($now - $conn->getLastActivity()) > $this->webSocketTimeout;
        }
        if ($conn->isSse()) {
            return ($now - $conn->getLastActivity()) > $this->sseTimeout;
        }
        return ($now - $conn->getLastActivity()) > $this->timeout;
    }
}
