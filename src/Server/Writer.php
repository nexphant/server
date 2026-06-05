<?php

namespace Nexph\Server\Server;

use Nexph\Server\Server\Connection;
use Nexph\Server\EventLoop;

class Writer {
    private EventLoop $loop;
    private int $maxWriteBufferSize;

    public function __construct(EventLoop $loop, int $maxWriteBufferSize) {
        $this->loop = $loop;
        $this->maxWriteBufferSize = $maxWriteBufferSize;
    }

    public function write(Connection $conn, string $data): int {
        return $conn->write($data, $this->maxWriteBufferSize);
    }

    public function flushPending(Connection $conn, bool $closeWhenDone, callable $onClose): void {
        $socket = $conn->getSocket();
        if (!$socket || !is_resource($socket)) {
            return;
        }

        $this->loop->addWriter($socket, function ($socket) use ($conn, $closeWhenDone, $onClose) {
            if ($conn->flush() < 0) {
                $onClose($conn);
                return;
            }

            if (!$conn->hasWriteBuffer()) {
                $this->loop->removeWriter($socket);
                if ($closeWhenDone) {
                    $onClose($conn);
                }
            }
        });
    }
}
