<?php

namespace nexphant\Server\Server;

class NativeConnection {
    private \Socket $socket;
    private int $id;
    private string $readBuffer = '';
    private string $writeBuffer = '';
    private int $writeOffset = 0;
    private float $lastActivity;
    private bool $keepAlive = true;
    private int $requestCount = 0;

    public function __construct(\Socket $socket, int $id) {
        $this->socket = $socket;
        $this->id = $id;
        $this->lastActivity = microtime(true);
        socket_set_nonblock($socket);
    }

    public function getId(): int {
        return $this->id;
    }

    public function getSocket(): \Socket {
        return $this->socket;
    }

    public function read(): ?string {
        $data = '';
        $bytes = @socket_recv($this->socket, $data, 65536, MSG_DONTWAIT);
        
        if ($bytes === false) {
            $error = socket_last_error($this->socket);
            if ($error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK) {
                return '';
            }
            return null;
        }
        
        if ($bytes === 0) {
            return null;
        }
        
        $this->readBuffer .= $data;
        $this->lastActivity = microtime(true);
        return $data;
    }

    public function getBuffer(): string {
        return $this->readBuffer;
    }

    public function consumeBuffer(int $length): void {
        $this->readBuffer = substr($this->readBuffer, $length);
    }

    public function write(string $data): int {
        $this->writeBuffer .= $data;
        return $this->flush();
    }

    public function writeFast(string $data): int {
        $written = @socket_send($this->socket, $data, strlen($data), MSG_DONTWAIT);
        
        if ($written === false) {
            $error = socket_last_error($this->socket);
            return ($error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK) ? 0 : -1;
        }
        
        $this->lastActivity = microtime(true);
        return $written;
    }

    public function flush(): int {
        if ($this->writeBuffer === '') {
            return 0;
        }

        $len = strlen($this->writeBuffer);
        $remaining = $len - $this->writeOffset;
        
        if ($remaining <= 0) {
            $this->writeBuffer = '';
            $this->writeOffset = 0;
            return 0;
        }

        $chunk = $this->writeOffset === 0 ? $this->writeBuffer : substr($this->writeBuffer, $this->writeOffset);
        $written = @socket_send($this->socket, $chunk, strlen($chunk), MSG_DONTWAIT);
        
        if ($written === false) {
            $error = socket_last_error($this->socket);
            if ($error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK) {
                return 0;
            }
            $this->writeBuffer = '';
            $this->writeOffset = 0;
            return -1;
        }

        if ($written > 0) {
            $this->writeOffset += $written;
            $this->lastActivity = microtime(true);
            
            if ($this->writeOffset >= $len) {
                $this->writeBuffer = '';
                $this->writeOffset = 0;
            }
        }

        return $written;
    }

    public function hasWriteBuffer(): bool {
        return $this->writeBuffer !== '' && $this->writeOffset < strlen($this->writeBuffer);
    }

    public function getWriteBufferSize(): int {
        return max(0, strlen($this->writeBuffer) - $this->writeOffset);
    }

    public function close(): void {
        @socket_close($this->socket);
        $this->readBuffer = '';
        $this->writeBuffer = '';
        $this->writeOffset = 0;
    }

    public function isAlive(): bool {
        return is_resource($this->socket) || $this->socket instanceof \Socket;
    }

    public function getLastActivity(): float {
        return $this->lastActivity;
    }

    public function setKeepAlive(bool $keepAlive): void {
        $this->keepAlive = $keepAlive;
    }

    public function isKeepAlive(): bool {
        return $this->keepAlive;
    }

    public function incrementRequestCount(): void {
        $this->requestCount++;
    }

    public function getRequestCount(): int {
        return $this->requestCount;
    }
}
