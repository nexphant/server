<?php
namespace Nexph\Server\Server;

class KeepAlive
{
    private array $connections = [];

    public function track($socket): void
    {
    }

    public function cleanup(): void
    {
    }
}
