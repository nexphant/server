<?php
namespace Nexphant\Server\Middleware;

use Nexphant\Server\ServerRequest;

interface MiddlewareInterface
{
    public function handle(ServerRequest $request, callable $next);
}
