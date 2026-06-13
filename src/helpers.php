<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (!function_exists('app')) {
    function app(): \Nexph\Server\Application
    {
        return \Nexph\Server\Application::getInstance();
    }
}

if (!function_exists('get')) {
    function get(string $path, callable $handler): void
    {
        app()->get($path, $handler);
    }
}

if (!function_exists('post')) {
    function post(string $path, callable $handler): void
    {
        app()->post($path, $handler);
    }
}

if (!function_exists('put')) {
    function put(string $path, callable $handler): void
    {
        app()->put($path, $handler);
    }
}

if (!function_exists('delete')) {
    function delete(string $path, callable $handler): void
    {
        app()->delete($path, $handler);
    }
}

if (!function_exists('patch')) {
    function patch(string $path, callable $handler): void
    {
        app()->patch($path, $handler);
    }
}

if (!function_exists('listen')) {
    function listen(int $port = 8080, string $host = '0.0.0.0'): void
    {
        app()->listen($port, $host);
    }
}

if (!function_exists('view')) {
    function view(string $view, array $data = []): \Nexph\View\ViewResponse
    {
        return \Nexph\View\view($view, $data);
    }
}
