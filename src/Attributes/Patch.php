<?php

namespace Nexph\Server\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Patch extends Route
{
    public function __construct(string $path, array $middleware = [])
    {
        parent::__construct($path, 'PATCH', $middleware);
    }
}
