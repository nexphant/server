<?php

namespace nexphant\Server\Server;

use nexphant\Server\ObjectPool;
use nexphant\Server\ObjectTracker;
use nexphant\Server\BufferSlab;

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

    public function stats(): array {
        return $this->pool->stats();
    }
}
