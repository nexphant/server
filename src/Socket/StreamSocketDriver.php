<?php

namespace Nexph\Server\Socket;

class StreamSocketDriver implements SocketDriverInterface
{
    public function listen(string $host, int $port): mixed
    {
        $uri = "tcp://$host:$port";
        $context = stream_context_create([
            'socket' => [
                'so_reuseaddr' => true,
                'so_reuseport' => true,
                'backlog' => 4096,
            ]
        ]);
        
        $socket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        
        if (!$socket) {
            throw new \RuntimeException("Failed to create server socket: $errstr ($errno)");
        }
        
        stream_set_blocking($socket, false);
        
        return $socket;
    }

    public function accept(mixed $server): mixed
    {
        $conn = @stream_socket_accept($server, 0);
        
        if ($conn === false) {
            return null;
        }
        
        stream_set_blocking($conn, false);
        stream_set_read_buffer($conn, 0);
        stream_set_write_buffer($conn, 0);
        
        return $conn;
    }

    public function read(mixed $conn): ?string
    {
        $data = @fread($conn, 8192);
        
        if ($data === false || $data === '') {
            return null;
        }
        
        return $data;
    }

    public function write(mixed $conn, string $data): int|false
    {
        return @fwrite($conn, $data);
    }

    public function close(mixed $conn): void
    {
        if (is_resource($conn)) {
            @fclose($conn);
        }
    }

    public function setNonBlocking(mixed $socket): void
    {
        stream_set_blocking($socket, false);
    }

    public function getLastError(mixed $socket): string
    {
        $error = error_get_last();
        return $error['message'] ?? 'Unknown error';
    }
}
