<?php

namespace Nexph\Server;

use Nexph\Lifecycle\Lifecycle;

class RequestLifecycle
{
    public static function handle($request, callable $handler): mixed
    {
        $ctx = Lifecycle::request();
        try {
            return $handler($request, $ctx);
        } finally {
            $ctx->close();
        }
    }
}
