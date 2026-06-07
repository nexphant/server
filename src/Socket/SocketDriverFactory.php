<?php

namespace Nexph\Server\Socket;

use Nexph\Support\Extension\ExtensionDetector;

class SocketDriverFactory
{
    public static function create(): SocketDriverInterface
    {
        $override = getenv('NEXPH_SOCKET');
        
        if ($override === 'native' && ExtensionDetector::has('sockets')) {
            return new NativeSocketDriver();
        }

        return new StreamSocketDriver();
    }

    public static function isValidSocket(mixed $socket): bool
    {
        return is_resource($socket) || $socket instanceof \Socket;
    }
}
