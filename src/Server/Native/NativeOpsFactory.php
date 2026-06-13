<?php

namespace Nexph\Server\Server\Native;

final class NativeOpsFactory
{
    public static function create(bool|array $nativeOps = false, ?string $library = null): NativeOps
    {
        if (is_array($nativeOps)) {
            $library = $library ?? ($nativeOps['native_lib'] ?? null);
            $nativeOps = (bool) ($nativeOps['native_ops'] ?? false);
        }
        $enabled = $nativeOps;
        if ($enabled) {
            $ffi = new FfiNativeOps($library);
            if ($ffi->available()) {
                return $ffi;
            }
        }
        return new PhpNativeOps();
    }
}
