<?php

namespace Nexph\Server\Socket;

use Nexph\Support\Extension\ExtensionDetector;

class SocketDriverFactory
{
    public static function create(): SocketDriverInterface
    {
        $override = getenv('NEXPH_SOCKET');
        
        if ($override === 'stream') {
            return new StreamSocketDriver();
        }
        
        if ($override === 'native' && ExtensionDetector::has('sockets')) {
            return new NativeSocketDriver();
        }

        if (ExtensionDetector::has('sockets')) {
            return new NativeSocketDriver();
        }

        return new StreamSocketDriver();
    }
}
