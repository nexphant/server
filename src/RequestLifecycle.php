<?php

namespace Nexphant\Server;

use Nexphant\Lifecycle\Lifecycle;
use Nexphant\Lifecycle\RequestOwner;

class RequestLifecycle
{
    public static function handle($request, callable $handler): mixed
    {
        $ctx = Lifecycle::request();
        try {
            return $handler($request, $ctx);
        } catch (\Throwable $e) {
            $ctx->cancel();
            throw $e;
        } finally {
            $ctx->close();
        }
    }
    
    public static function wrap(callable $handler): callable
    {
        return function($request, $response, $params = []) use ($handler) {
            $ctx = Lifecycle::request();
            try {
                return $handler($request, $response, $params, $ctx);
            } catch (\Throwable $e) {
                $ctx->cancel();
                throw $e;
            } finally {
                $ctx->close();
            }
        };
    }
}
