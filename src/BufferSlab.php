<?php

namespace Nexph\Server;

class BufferSlab implements Resettable, Cleanable {
    private string $data = '';
    private int $peakBytes = 0;

    public function append(string $chunk): void {
        $this->data .= $chunk;
        $this->peakBytes = max($this->peakBytes, strlen($this->data));
    }

    public function get(): string {
        return $this->data;
    }

    public function length(): int {
        return strlen($this->data);
    }

    public function consume(int $length): void {
        $this->data = substr($this->data, $length);
    }

    public function reset(): void {
        $this->data = '';
        $this->peakBytes = 0;
    }

    public function isClean(): bool {
        return $this->data === '';
    }

    public function peakBytes(): int {
        return $this->peakBytes;
    }
}
