<?php

namespace Nexphant\Server\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Post extends Route
{
    public function __construct(string $path, array $middleware = [])
    {
        parent::__construct($path, 'POST', $middleware);
    }
}
