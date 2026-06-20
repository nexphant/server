<?php

namespace Nexphant\Server\Server;

use Nexphant\Server\ObjectPool;
use Nexphant\Server\ObjectTracker;
use Nexphant\Server\BufferSlab;

class BufferPool {
    private ObjectPool $pool;

    public function __construct(
        int $maxSize = 2048,
        ?ObjectTracker $tracker = null,
        bool $safety = false,
    ) {
        $this->pool = new ObjectPool(
            fn() => new BufferSlab(),
            $maxSize,
            fn(BufferSlab $buffer) => $buffer->reset(),
            'buffer',
            $tracker,
            $safety
        );
    }

    public function acquire(string $owner = '', string $context = ''): BufferSlab {
        /** @var BufferSlab $buffer */
        $buffer = $this->pool->acquire($owner, $context);
        return $buffer;
    }

    public function release(BufferSlab $buffer): void {
        $this->pool->release($buffer);
    }

    public function cleanup(int $maxIdleTimeSec = 30): int
    {
        return $this->pool->cleanup($maxIdleTimeSec);
    }

    public function stats(): array {
        return $this->pool->stats();
    }
}
