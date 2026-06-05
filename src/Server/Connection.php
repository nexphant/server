<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Server\Server;

use Nexph\Server\BufferSlab;

class Connection {
    private $socket;
    private BufferSlab $buffer;
    private BufferSlab $writeBuffer;
    private ?BufferPool $bufferPool;
    private int $id;
    private float $connectedAt;
    private float $lastActivity;
    private float $lastReadAt;
    private float $lastWriteAt;
    private float $lastPingAt = 0.0;
    private float $lastPongAt = 0.0;
    private string $remoteAddr;
    private int $remotePort;
    private bool $keepAlive = true;
    private int $requestCount = 0;
    private bool $webSocket = false;
    private string $webSocketPath = '';
    private bool $sse = false;
    private string $ssePath = '';
    private string $sseChannel = 'global';
    private bool $closing = false;

    public function __construct($socket, int $id, ?BufferPool $bufferPool = null) {
        $this->socket = $socket;
        $this->id = $id;
        $this->bufferPool = $bufferPool;
        $this->buffer = $bufferPool?->acquire('connection', "read:{$id}") ?? new BufferSlab();
        $this->writeBuffer = $bufferPool?->acquire('connection', "write:{$id}") ?? new BufferSlab();
        $this->connectedAt = microtime(true);
        $this->lastActivity = $this->connectedAt;
        $this->lastReadAt = $this->connectedAt;
        $this->lastWriteAt = $this->connectedAt;
        $this->lastPongAt = $this->connectedAt;

        stream_set_blocking($socket, false);
        
        // Track socket resource (skip if not object in PHP 8.0)
        if (class_exists('\Nexph\Core\Resource\ResourceRegistry') && class_exists('\Nexph\Runtime\Runtime') && \Nexph\Runtime\Runtime::available()) {
            if (is_object($socket)) {
                \Nexph\Core\Resource\ResourceRegistry::instance()->track(
                    $socket,
                    'socket',
                    \Nexph\Runtime\Runtime::context()->ownerId()
                );
            }
        }

        $name = stream_socket_get_name($socket, true);
        if ($name && strpos($name, ':') !== false) {
            [$this->remoteAddr, $this->remotePort] = explode(':', $name);
            $this->remotePort = (int) $this->remotePort;
        } else {
            $this->remoteAddr = '0.0.0.0';
            $this->remotePort = 0;
        }
    }

    public function getId(): int {
        return $this->id;
    }

    public function getSocket() {
        return $this->socket;
    }

    public function getRemoteAddr(): string {
        return $this->remoteAddr;
    }

    public function getRemotePort(): int {
        return $this->remotePort;
    }

    public function read(): ?string {
        if (!is_resource($this->socket)) {
            return null;
        }

        $data = @fread($this->socket, 65536);

        if ($data === false || $data === '') {
            if (!is_resource($this->socket) || feof($this->socket)) {
                return null;
            }
            return '';
        }

        $now = microtime(true);
        $this->lastReadAt = $now;
        $this->lastActivity = $now;
        $this->buffer->append($data);
        return $data;
    }

    public function getBuffer(): string {
        return $this->buffer->get();
    }

    public function consumeBuffer(int $length): void {
        $this->buffer->consume($length);
    }

    public function clearBuffer(): void {
        $this->buffer->reset();
    }

    public function write(string $data, int $maxBufferSize = 0): int {
        if (!is_resource($this->socket)) {
            return -1;
        }

        if ($maxBufferSize > 0 && $this->writeBuffer->length() + strlen($data) > $maxBufferSize) {
            return -2;
        }

        $this->writeBuffer->append($data);
        return $this->flush();
    }

    public function flush(): int {
        if ($this->writeBuffer->length() === 0) {
            return 0;
        }

        if (!is_resource($this->socket)) {
            $this->writeBuffer->reset();
            return -1;
        }

        $written = @fwrite($this->socket, $this->writeBuffer->get());

        if ($written === false) {
            $this->writeBuffer->reset();
            return -1;
        }

        if ($written > 0) {
            $this->writeBuffer->consume($written);
            $this->lastWriteAt = microtime(true);
            $this->lastActivity = max($this->lastActivity, $this->lastWriteAt);
        }

        return $written;
    }

    public function hasWriteBuffer(): bool {
        return $this->writeBuffer->length() > 0;
    }

    public function getWriteBufferSize(): int {
        return $this->writeBuffer->length();
    }

    public function close(): void {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
            $this->socket = null;
        }
        if ($this->bufferPool) {
            $this->bufferPool->release($this->buffer);
            $this->bufferPool->release($this->writeBuffer);
            $this->bufferPool = null;
        } else {
            $this->buffer->reset();
            $this->writeBuffer->reset();
        }
    }

    public function isAlive(): bool {
        return is_resource($this->socket);
    }

    public function getLastActivity(): float {
        return $this->lastActivity;
    }

    public function touch(): void {
        $this->lastActivity = microtime(true);
    }

    public function getLastReadAt(): float {
        return $this->lastReadAt;
    }

    public function getLastWriteAt(): float {
        return $this->lastWriteAt;
    }

    public function getLastPingAt(): float {
        return $this->lastPingAt;
    }

    public function markPing(): void {
        $this->lastPingAt = microtime(true);
    }

    public function getLastPongAt(): float {
        return $this->lastPongAt;
    }

    public function markPong(): void {
        $this->lastPongAt = microtime(true);
        $this->lastReadAt = $this->lastPongAt;
        $this->lastActivity = max($this->lastActivity, $this->lastPongAt);
    }

    public function getConnectedAt(): float {
        return $this->connectedAt;
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

    public function markWebSocket(string $path): void {
        $now = microtime(true);
        $this->webSocket = true;
        $this->webSocketPath = $path;
        $this->keepAlive = true;
        $this->lastPingAt = 0.0;
        $this->lastPongAt = $now;
        $this->lastReadAt = $now;
        $this->lastWriteAt = $now;
        $this->lastActivity = $now;
    }

    public function markSse(string $path, string $channel = 'global'): void {
        $now = microtime(true);
        $this->sse = true;
        $this->ssePath = $path;
        $this->sseChannel = $channel;
        $this->keepAlive = true;
        $this->lastReadAt = $now;
        $this->lastWriteAt = $now;
        $this->lastActivity = $now;
    }

    public function isWebSocket(): bool {
        return $this->webSocket;
    }

    public function getWebSocketPath(): string {
        return $this->webSocketPath;
    }

    public function isSse(): bool {
        return $this->sse;
    }

    public function getSsePath(): string {
        return $this->ssePath;
    }

    public function getSseChannel(): string {
        return $this->sseChannel;
    }

    public function markClosing(): void {
        $this->closing = true;
    }

    public function isClosing(): bool {
        return $this->closing;
    }
}
