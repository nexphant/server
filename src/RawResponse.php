<?php

namespace Nexph\Server;

class RawResponse {
    public function __construct(public readonly string $http) {}
}
