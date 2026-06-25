<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (!function_exists('app')) {
    function app(): \Nexphant\Server\Application
    {
        return \Nexphant\Server\Application::getInstance();
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
    function view(string $view, array $data = []): \Nexphant\View\ViewResponse
    {
        return \Nexphant\View\view($view, $data);
    }
}

if (!function_exists('session')) {
    function session(): \Nexphant\Server\Session\Session
    {
        global $__nx_request;
        return $__nx_request?->getAttribute('session') ?? throw new \RuntimeException('Session not available');
    }
}

if (!function_exists('auth')) {
    function auth(): \Nexphant\Server\Auth\AuthManager
    {
        global $__nx_request;
        return $__nx_request?->getAttribute('auth') ?? throw new \RuntimeException('Auth not available');
    }
}

if (!function_exists('auth_check')) {
    function auth_check(): bool
    {
        global $__nx_request;
        $auth = $__nx_request?->getAttribute('auth');
        return $auth !== null && $auth->check();
    }
}

if (!function_exists('auth_id')) {
    function auth_id(): int|string|null
    {
        return auth()->id();
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): mixed
    {
        return auth()->user();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        global $__nx_request;
        $session = $__nx_request?->getAttribute('session') ?? throw new \RuntimeException('Session not available');
        $csrf = new \Nexphant\Server\Csrf\CsrfManager($session);
        return $csrf->token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = null): mixed
    {
        return session()->old($key, $default);
    }
}

if (!function_exists('cookie')) {
    function cookie(string $name, string $value = '', int $maxAge = 0): \Nexphant\Server\Cookie\Cookie
    {
        return \Nexphant\Server\Cookie\Cookie::make($name, $value, $maxAge);
    }
}

if (!function_exists('response')) {
    function response(): \Nexphant\Server\Http\ResponseBuilder
    {
        return new \Nexphant\Server\Http\ResponseBuilder();
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url = '', int $status = 302): mixed
    {
        $builder = new \Nexphant\Server\Http\ResponseBuilder();
        if ($url !== '') {
            return $builder->redirect($url, $status);
        }
        return $builder;
    }
}

if (!function_exists('json')) {
    function json(mixed $data, int $status = 200): \Nexphant\Server\ServerResponse
    {
        return (new \Nexphant\Server\Http\ResponseBuilder())->status($status)->json($data);
    }
}

if (!function_exists('cookie_jar')) {
    function cookie_jar(): \Nexphant\Server\Cookie\CookieJar
    {
        global $__nx_request;
        return $__nx_request?->getAttribute('cookies') ?? throw new \RuntimeException('CookieJar not available');
    }
}
