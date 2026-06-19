<?php

namespace Nexphant\Server;

class BufferSlab implements Resettable, Cleanable {
    private string $data = '';
    private int $offset = 0;
    private int $peakBytes = 0;

    public function append(string $chunk): void {
        $this->data .= $chunk;
        $this->peakBytes = max($this->peakBytes, strlen($this->data) - $this->offset);
    }

    public function get(): string {
        return $this->offset > 0 ? substr($this->data, $this->offset) : $this->data;
    }

    public function length(): int {
        return strlen($this->data) - $this->offset;
    }

    public function consume(int $length): void {
        $this->offset += $length;
        if ($this->offset > 1024) {
            $this->data = substr($this->data, $this->offset);
            $this->offset = 0;
        }
    }

    public function reset(): void {
        if ($this->offset > 0 && $this->data !== '') {
            $this->data = substr($this->data, $this->offset);
            $this->offset = 0;
        }
        $this->data = '';
        $this->offset = 0;
        $this->peakBytes = 0;
    }

    public function isClean(): bool {
        return strlen($this->data) === $this->offset;
    }

    public function peakBytes(): int {
        return $this->peakBytes;
    }
}
