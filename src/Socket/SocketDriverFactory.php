<?php

namespace Nexph\Server\Socket;

use Nexph\Support\Extension\ExtensionDetector;

class SocketDriverFactory
{
    public static function create(): SocketDriverInterface
    {
        if (ExtensionDetector::has('sockets')) {
            return new NativeSocketDriver();
        }

        return new StreamSocketDriver();
    }
}
