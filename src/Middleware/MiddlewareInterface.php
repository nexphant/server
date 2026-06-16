<?php
namespace nexphant\Server\Middleware;

use nexphant\Server\ServerRequest;

interface MiddlewareInterface
{
    public function handle(ServerRequest $request, callable $next);
}
