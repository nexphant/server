<?php

namespace Nexph\Server\Socket;

interface SocketDriverInterface
{
    public function listen(string $host, int $port): mixed;
    public function accept(mixed $server): mixed;
    public function read(mixed $conn): ?string;
    public function write(mixed $conn, string $data): int|false;
    public function close(mixed $conn): void;
    public function setNonBlocking(mixed $socket): void;
    public function getLastError(mixed $socket): string;
}
