<?php

/**
 * Nexphant Path Helpers
 *
 * All helpers resolve relative to NEXPHANT_BASE_PATH (set by App::create()).
 * Falls back to getcwd() if constant not defined.
 */

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = defined('NEXPHANT_BASE_PATH') ? NEXPHANT_BASE_PATH : getcwd();
        return $path !== '' ? rtrim($base, '/') . '/' . ltrim($path, '/') : $base;
    }
}

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        return base_path($path !== '' ? 'app/' . ltrim($path, '/') : 'app');
    }
}

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return base_path($path !== '' ? 'config/' . ltrim($path, '/') : 'config');
    }
}

if (!function_exists('database_path')) {
    function database_path(string $path = ''): string
    {
        return base_path($path !== '' ? 'database/' . ltrim($path, '/') : 'database');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path($path !== '' ? 'storage/' . ltrim($path, '/') : 'storage');
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return base_path($path !== '' ? 'public/' . ltrim($path, '/') : 'public');
    }
}

if (!function_exists('resources_path')) {
    function resources_path(string $path = ''): string
    {
        return base_path($path !== '' ? 'resources/' . ltrim($path, '/') : 'resources');
    }
}

if (!function_exists('bootstrap_path')) {
    function bootstrap_path(string $path = ''): string
    {
        return base_path($path !== '' ? 'bootstrap/' . ltrim($path, '/') : 'bootstrap');
    }
}

if (!function_exists('runtime_path')) {
    function runtime_path(string $path = ''): string
    {
        return storage_path($path !== '' ? 'framework/' . ltrim($path, '/') : 'framework');
    }
}

if (!function_exists('cache_path')) {
    function cache_path(string $path = ''): string
    {
        return storage_path($path !== '' ? 'cache/' . ltrim($path, '/') : 'cache');
    }
}

if (!function_exists('logs_path')) {
    function logs_path(string $path = ''): string
    {
        return storage_path($path !== '' ? 'logs/' . ltrim($path, '/') : 'logs');
    }
}

if (!function_exists('routes_path')) {
    function routes_path(string $path = ''): string
    {
        return base_path($path !== '' ? 'routes/' . ltrim($path, '/') : 'routes');
    }
}

if (!function_exists('tests_path')) {
    function tests_path(string $path = ''): string
    {
        return base_path($path !== '' ? 'tests/' . ltrim($path, '/') : 'tests');
    }
}
