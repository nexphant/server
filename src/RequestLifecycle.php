<?php

namespace Nexph\Server;

use Nexph\Lifecycle\Lifecycle;

class RequestLifecycle
{
    public static function handle($request, $server): mixed
    {
        $ctx = Lifecycle::request();
        try {
            $response = $server->handleRequest($request, $ctx);
            return $response;
        } finally {
            $ctx->close();
        }
    }
}
