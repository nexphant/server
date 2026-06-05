<?php

namespace Nexph\Server\Server;

use Nexph\Server\EventLoop;

class Server {
    private EventLoop $loop;
    private $socket;
    private string $host;
    private int $port;
    private int $backlog;

    public function __construct(EventLoop $loop, string $host, int $port, int $backlog = 4096) {
        $this->loop = $loop;
        $this->host = $host;
        $this->port = $port;
        $this->backlog = $backlog;
    }

    public function listen(callable $onAccept): void {
        $context = stream_context_create([
            'socket' => ['backlog' => $this->backlog, 'so_reuseport' => true],
        ]);

        $this->socket = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$this->socket) {
            throw new \RuntimeException("Failed to start server: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->socket, false);
        $this->loop->addReader($this->socket, fn($socket) => $this->acceptBatch($onAccept));
    }

    private function acceptBatch(callable $onAccept): void {
        for ($i = 0; $i < 128; $i++) {
            $client = @stream_socket_accept($this->socket, 0);
            if (!$client) {
                break;
            }
            $onAccept($client);
        }
    }

    public function close(): void {
        if ($this->socket && is_resource($this->socket)) {
            $this->loop->removeReader($this->socket);
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function getSocket() {
        return $this->socket;
    }
}
