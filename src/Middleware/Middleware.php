<?php
namespace Nexphant\Server\Middleware;

use Nexphant\Server\ServerRequest;
use Nexphant\Server\ServerResponse;

interface Middleware
{
    public function handle(ServerRequest $request, callable $next): ServerResponse;
}
