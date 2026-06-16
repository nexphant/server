<?php

namespace nexphant\Server\Socket;

class NativeSocketDriver implements SocketDriverInterface
{
    private bool $reusePort = true;

    public function __construct(array $config = [])
    {
        if (!extension_loaded('sockets')) {
            throw new \RuntimeException('ext-sockets is not available');
        }
        $this->reusePort = (bool) ($config['reuse_port'] ?? true);
    }

    public function listen(string $host, int $port): mixed
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($socket === false) {
            throw new \RuntimeException('Failed to create socket: ' . socket_strerror(socket_last_error()));
        }
        
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if ($this->reusePort && defined('SO_REUSEPORT')) {
            socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1);
        }
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 262144);
        socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 262144);
        
        if (!socket_bind($socket, $host, $port)) {
            $error = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new \RuntimeException("Failed to bind socket: $error");
        }
        
        if (!socket_listen($socket, 4096)) {
            $error = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new \RuntimeException("Failed to listen on socket: $error");
        }
        
        socket_set_nonblock($socket);
        
        return $socket;
    }

    public function accept(mixed $server): mixed
    {
        $conn = @socket_accept($server);
        
        if ($conn === false) {
            return null;
        }
        
        socket_set_nonblock($conn);
        socket_set_option($conn, SOL_TCP, TCP_NODELAY, 1);
        
        return $conn;
    }

    public function read(mixed $conn): ?string
    {
        $buffer = '';
        $bytes = @socket_recv($conn, $buffer, 8192, MSG_DONTWAIT);
        
        if ($bytes === false || $bytes === 0) {
            return null;
        }
        
        return $buffer;
    }

    public function write(mixed $conn, string $data): int|false
    {
        $bytes = @socket_send($conn, $data, strlen($data), MSG_DONTWAIT);
        
        return $bytes === false ? false : $bytes;
    }

    public function close(mixed $conn): void
    {
        if (is_resource($conn) || $conn instanceof \Socket) {
            @socket_close($conn);
        }
    }

    public function setNonBlocking(mixed $socket): void
    {
        socket_set_nonblock($socket);
    }

    public function getLastError(mixed $socket): string
    {
        return socket_strerror(socket_last_error($socket));
    }
}
