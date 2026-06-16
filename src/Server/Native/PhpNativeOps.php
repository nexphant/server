<?php

namespace nexphant\Server\Server\Native;

final class PhpNativeOps implements NativeOps
{
    public function findHeaderEnd(string $buffer, int $offset = 0): int|false
    {
        return strpos($buffer, "\r\n\r\n", $offset);
    }

    public function hasConnectionClose(string $buffer): bool
    {
        return stripos($buffer, "Connection: close") !== false;
    }

    public function available(): bool
    {
        return false;
    }
}
