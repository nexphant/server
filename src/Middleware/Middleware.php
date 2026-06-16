<?php
namespace nexphant\Server\Middleware;

use nexphant\Server\ServerRequest;
use nexphant\Server\ServerResponse;

interface Middleware
{
    public function handle(ServerRequest $request, callable $next): ServerResponse;
}
