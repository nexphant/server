<?php
namespace Nexph\Server\Middleware;

use Nexph\Server\ServerRequest;
use Nexph\Server\ServerResponse;

interface Middleware
{
    public function handle(ServerRequest $request, callable $next): ServerResponse;
}
