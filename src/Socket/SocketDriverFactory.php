<?php

namespace Nexph\Server\Socket;

use Nexph\Support\Extension\ExtensionDetector;

class SocketDriverFactory
{
    public static function create(?string $preferred = null, array $config = []): SocketDriverInterface
    {
        $override = getenv('NEXPH_SOCKET') ?: $preferred;
        
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
