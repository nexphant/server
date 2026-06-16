<?php

namespace nexphant\Server\Socket;

use nexphant\Support\Extension\ExtensionDetector;

class SocketDriverFactory
{
    public static function create(?string $preferred = null, array $config = []): SocketDriverInterface
    {
        $override = getenv('nexphant_SOCKET') ?: $preferred;
        
        if (($override === 'native' || $override === 'auto') && ExtensionDetector::has('sockets')) {
            return new NativeSocketDriver($config);
        }

        return new StreamSocketDriver();
    }

    public static function isValidSocket(mixed $socket): bool
    {
        return is_resource($socket) || $socket instanceof \Socket;
    }
}
