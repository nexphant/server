<?php

namespace Nexph\Server\Server;

use Nexph\Server\Socket\SocketDriverInterface;

final class NativeFastLoop
{
    private ?\EventBase $base = null;
    private array $readEvents = [];
    private array $writeEvents = [];
    private array $signalEvents = [];
    private array $buffers = [];
    private array $writeBuffers = [];
    private array $requestCounts = [];
    private array $sockets = [];
    private int $socketCount = 0;
    private string $primaryStartLine;
    private int $primaryStartLineLength;
    private string $primaryKeepAliveResponse;
    private string $primaryCloseResponse;
    private const NOT_FOUND = "HTTP/1.1 404 Not Found\r\nContent-Type: application/json\r\nContent-Length: 21\r\nConnection: close\r\n\r\n{\"error\":\"Not Found\"}";

    public function __construct(
        private readonly SocketDriverInterface $driver,
        private readonly \Socket $serverSocket,
        private readonly FastPathEngine $engine,
        private readonly int $maxConnections,
        private readonly int $maxAcceptPerTick,
        private readonly int $maxRequestsPerConnection,
        private readonly int $readChunkSize = 65536,
    ) {
        $this->primaryStartLine = $engine->primaryStartLine();
        $this->primaryStartLineLength = $engine->primaryStartLineLength();
        $this->primaryKeepAliveResponse = $engine->primaryKeepAliveResponse();
        $this->primaryCloseResponse = $engine->primaryCloseResponse();
    }

    public static function supported(): bool
    {
        return extension_loaded('event') && extension_loaded('sockets');
    }

    public function run(): void
    {
        $this->base = new \EventBase();
        $accept = new \Event($this->base, $this->serverSocket, \Event::READ | \Event::PERSIST, function (): void {
            $this->acceptConnections();
        });
        $accept->add();
        $this->signal(SIGINT);
        $this->signal(SIGTERM);
        $this->base->loop();
    }

    private function signal(int $signal): void
    {
        if (!defined('SIGINT')) {
            return;
        }
        $event = \Event::signal($this->base, $signal, function (): void {
            $this->base?->stop();
        });
        $event->add();
        $this->signalEvents[$signal] = $event;
    }

    private function acceptConnections(): void
    {
        for ($i = 0; $i < $this->maxAcceptPerTick; $i++) {
            $client = $this->driver->accept($this->serverSocket);
            if (!$client) {
                return;
            }
            if ($this->socketCount >= $this->maxConnections) {
                @socket_close($client);
                continue;
            }
            $this->open($client);
        }
    }

    private function open(\Socket $socket): void
    {
        $id = spl_object_id($socket);
        $this->sockets[$id] = $socket;
        $this->socketCount++;
        $this->buffers[$id] = '';
        $this->writeBuffers[$id] = '';
        $this->requestCounts[$id] = 0;
        $event = new \Event($this->base, $socket, \Event::READ | \Event::PERSIST, function () use ($socket, $id): void {
            $this->read($socket, $id);
        });
        $event->add();
        $this->readEvents[$id] = $event;
    }

    private function read(\Socket $socket, int $id): void
    {
        $chunk = '';
        $bytes = @socket_recv($socket, $chunk, $this->readChunkSize, MSG_DONTWAIT);
        if ($bytes === false) {
            $error = socket_last_error($socket);
            if ($error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK) {
                return;
            }
            $this->close($id);
            return;
        }
        if ($bytes === 0) {
            $this->close($id);
            return;
        }
        $this->buffers[$id] = $this->buffers[$id] === '' ? $chunk : $this->buffers[$id] . $chunk;
        $this->processBuffer($socket, $id);
    }

    private function processBuffer(\Socket $socket, int $id): void
    {
        while (($this->buffers[$id] ?? '') !== '') {
            $buffer = $this->buffers[$id];
            if ($this->primaryStartLine !== '' && strncmp($buffer, $this->primaryStartLine, $this->primaryStartLineLength) === 0) {
                if (!$this->writePrimary($socket, $id, $buffer)) {
                    return;
                }
                continue;
            }
            $match = $this->engine->match($buffer);
            if ($match === null) {
                if (strpos($buffer, "\r\n\r\n") === false) {
                    return;
                }
                $this->write($socket, $id, self::NOT_FOUND, true);
                return;
            }
            $this->buffers[$id] = $match['consumed'] === strlen($buffer) ? '' : substr($buffer, $match['consumed']);
            $this->requestCounts[$id]++;
            $keepAlive = $match['keep_alive'] && $this->requestCounts[$id] < $this->maxRequestsPerConnection;
            $this->write($socket, $id, $this->engine->getResponse($match['key'], $keepAlive), !$keepAlive);
            if (!$keepAlive || ($this->writeBuffers[$id] ?? '') !== '') {
                return;
            }
        }
    }

    private function writePrimary(\Socket $socket, int $id, string $buffer): bool
    {
        $primary = strpos($buffer, "\r\n\r\n", $this->primaryStartLineLength);
        if ($primary === false) {
            return false;
        }
        $primary += 4;
        $keepAlive = $this->requestCounts[$id] + 1 < $this->maxRequestsPerConnection;
        $this->buffers[$id] = $primary === strlen($buffer) ? '' : substr($buffer, $primary);
        $this->requestCounts[$id]++;
        $this->write($socket, $id, $keepAlive ? $this->primaryKeepAliveResponse : $this->primaryCloseResponse, !$keepAlive);
        return $keepAlive && ($this->writeBuffers[$id] ?? '') === '';
    }

    private function write(\Socket $socket, int $id, string $data, bool $closeWhenDone): void
    {
        $length = strlen($data);
        $written = @socket_send($socket, $data, $length, MSG_DONTWAIT);
        if ($written === false) {
            $error = socket_last_error($socket);
            if ($error !== SOCKET_EAGAIN && $error !== SOCKET_EWOULDBLOCK) {
                $this->close($id);
                return;
            }
            $written = 0;
        }
        if ($written < $length) {
            $this->writeBuffers[$id] .= substr($data, $written);
            $this->flushLater($socket, $id, $closeWhenDone);
            return;
        }
        if ($closeWhenDone) {
            $this->close($id);
        }
    }

    private function flushLater(\Socket $socket, int $id, bool $closeWhenDone): void
    {
        if (isset($this->writeEvents[$id])) {
            return;
        }
        $event = new \Event($this->base, $socket, \Event::WRITE | \Event::PERSIST, function () use ($socket, $id, $closeWhenDone): void {
            $this->flush($socket, $id, $closeWhenDone);
        });
        $event->add();
        $this->writeEvents[$id] = $event;
    }

    private function flush(\Socket $socket, int $id, bool $closeWhenDone): void
    {
        $buffer = $this->writeBuffers[$id] ?? '';
        if ($buffer === '') {
            $this->writeEvents[$id]->del();
            unset($this->writeEvents[$id]);
            if ($closeWhenDone) {
                $this->close($id);
            }
            return;
        }
        $written = @socket_send($socket, $buffer, strlen($buffer), MSG_DONTWAIT);
        if ($written === false) {
            $error = socket_last_error($socket);
            if ($error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK) {
                return;
            }
            $this->close($id);
            return;
        }
        $this->writeBuffers[$id] = substr($buffer, $written);
    }

    private function close(int $id): void
    {
        if (isset($this->readEvents[$id])) {
            $this->readEvents[$id]->del();
            unset($this->readEvents[$id]);
        }
        if (isset($this->writeEvents[$id])) {
            $this->writeEvents[$id]->del();
            unset($this->writeEvents[$id]);
        }
        if (isset($this->sockets[$id])) {
            @socket_close($this->sockets[$id]);
            $this->socketCount--;
        }
        unset($this->sockets[$id], $this->buffers[$id], $this->writeBuffers[$id], $this->requestCounts[$id]);
    }
}
