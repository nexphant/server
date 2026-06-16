<?php

namespace nexphant\Server\Socket;

class AcceptStrategy
{
    public const SHARED_ACCEPT = 'shared';
    public const REUSEPORT = 'reuseport';
    public const MASTER_ACCEPT = 'master';

    private static ?string $strategy = null;

    public static function detect(): string
    {
        if (self::$strategy !== null) {
            return self::$strategy;
        }

        if (extension_loaded('sockets')) {
            if (defined('SO_REUSEPORT')) {
                self::$strategy = self::REUSEPORT;
            } else {
                self::$strategy = self::SHARED_ACCEPT;
            }
        } else {
            self::$strategy = self::SHARED_ACCEPT;
        }

        return self::$strategy;
    }

    public static function set(string $strategy): void
    {
        self::$strategy = $strategy;
    }

    public static function isReusePort(): bool
    {
        return self::detect() === self::REUSEPORT;
    }
}
