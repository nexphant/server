<?php
namespace Nexph\Server\Middleware;

use Nexph\Server\ServerRequest;

interface MiddlewareInterface
{
    public function handle(ServerRequest $request, callable $next);
}
