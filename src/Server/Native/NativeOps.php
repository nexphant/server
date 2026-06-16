<?php

namespace nexphant\Server\Server\Native;

interface NativeOps
{
    public function findHeaderEnd(string $buffer, int $offset = 0): int|false;
    public function hasConnectionClose(string $buffer): bool;
    public function available(): bool;
}
