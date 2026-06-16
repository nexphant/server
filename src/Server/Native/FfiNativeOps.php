<?php

namespace Nexphant\Server\Server\Native;

final class FfiNativeOps implements NativeOps
{
    private const CDEF = <<<'CDEF'
int NEXPHANT_find_header_end(const char *buffer, size_t length, size_t offset);
int NEXPHANT_has_connection_close(const char *buffer, size_t length);
CDEF;

    private ?\FFI $ffi = null;

    public function __construct(?string $library = null)
    {
        if (!class_exists(\FFI::class)) {
            return;
        }
        $library ??= getenv('NEXPHANT_NATIVE_LIB') ?: '';
        if ($library === '' || !is_file($library)) {
            return;
        }
        try {
            $this->ffi = \FFI::cdef(self::CDEF, $library);
        } catch (\Throwable) {
            $this->ffi = null;
        }
    }

    public function findHeaderEnd(string $buffer, int $offset = 0): int|false
    {
        if ($this->ffi === null) {
            return false;
        }
        $pos = $this->ffi->NEXPHANT_find_header_end($buffer, strlen($buffer), max(0, $offset));
        return $pos >= 0 ? $pos : false;
    }

    public function hasConnectionClose(string $buffer): bool
    {
        if ($this->ffi === null) {
            return stripos($buffer, "Connection: close") !== false;
        }
        return $this->ffi->NEXPHANT_has_connection_close($buffer, strlen($buffer)) === 1;
    }

    public function available(): bool
    {
        return $this->ffi !== null;
    }
}
