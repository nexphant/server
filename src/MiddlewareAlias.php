<?php

namespace Nexphant\Server;

/**
 * Middleware alias registry — maps short names to FQCN.
 *
 * Usage:
 *   MiddlewareAlias::register('auth', AuthMiddleware::class);
 *   MiddlewareAlias::resolve('auth'); // → AuthMiddleware::class
 */
final class MiddlewareAlias
{
    private static array $aliases = [];

    public static function register(string $alias, string $class): void
    {
        self::$aliases[$alias] = $class;
    }

    public static function resolve(string $aliasOrClass): string
    {
        return self::$aliases[$aliasOrClass] ?? $aliasOrClass;
    }

    public static function all(): array
    {
        return self::$aliases;
    }
}
