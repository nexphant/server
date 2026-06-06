<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Server;

class Router {
    private array $routes = [];
    private array $exactRoutes = [];
    private array $middleware = [];
    private string $prefix = '';
    private bool $compiled = false;
    private array $compiledRegex = [];
    private array $compiledHandlers = [];

    public function get(string $path, callable $handler, array $middleware = []): self {
        return $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): self {
        return $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable $handler, array $middleware = []): self {
        return $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable $handler, array $middleware = []): self {
        return $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, array $middleware = []): self {
        return $this->add('DELETE', $path, $handler, $middleware);
    }

    public function options(string $path, callable $handler, array $middleware = []): self {
        return $this->add('OPTIONS', $path, $handler, $middleware);
    }

    public function any(string $path, callable $handler, array $middleware = []): self {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->add($method, $path, $handler, $middleware);
        }
        return $this;
    }

    public function add(string $method, string $path, callable $handler, array $middleware = []): self {
        $method = strtoupper($method);
        $fullPath = $this->prefix . $path;

        $route = [
            'method' => $method,
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => array_merge($this->middleware, $middleware),
        ];
        $this->routes[] = $route;
        $this->compiled = false;

        if (!str_contains($fullPath, '{')) {
            $this->exactRoutes[$method][$fullPath] = $route;
        }

        return $this;
    }

    public function group(string $prefix, callable $callback): self {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->middleware;

        $this->prefix .= $prefix;
        $callback($this);

        $this->prefix = $previousPrefix;
        $this->middleware = $previousMiddleware;

        return $this;
    }

    public function middleware(callable ...$middleware): self {
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    public function match(string $method, string $path): ?array {
        // O(1) exact match
        if (isset($this->exactRoutes[$method][$path])) {
            $route = $this->exactRoutes[$method][$path];
            return [
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
                'params' => [],
            ];
        }

        // Compiled combined regex
        $this->compile();

        if (isset($this->compiledRegex[$method])) {
            if (preg_match($this->compiledRegex[$method], $path, $matches)) {
                $mark = $matches['MARK'];
                $route = $this->compiledHandlers[$method][(int) $mark];
                $params = [];
                foreach ($route['paramNames'] as $name) {
                    if (isset($matches[$name]) && $matches[$name] !== '') {
                        $params[$name] = $matches[$name];
                    }
                }
                return [
                    'handler' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    public function dispatch(ServerRequest $request, ServerResponse $response): \Generator {
        return $this->dispatchAsync($request, $response);
    }

    public function dispatchSync(ServerRequest $request, ServerResponse $response): void {
        $route = $this->match($request->method, $request->path);

        if (!$route) {
            $response->notFound();
            return;
        }

        foreach ($route['params'] as $key => $value) {
            $request->setAttribute($key, $value);
        }

        foreach ($route['middleware'] as $middleware) {
            $result = $middleware($request, $response, $route['params']);
            if ($result === false || $response->isSent()) {
                return;
            }
        }

        ($route['handler'])($request, $response, $route['params']);
    }

    public function dispatchAsync(ServerRequest $request, ServerResponse $response): \Generator {
        $route = $this->match($request->method, $request->path);

        if (!$route) {
            $response->notFound();
            return;
        }

        foreach ($route['params'] as $key => $value) {
            $request->setAttribute($key, $value);
        }

        foreach ($route['middleware'] as $middleware) {
            $result = $middleware($request, $response, $route['params']);
            if ($result instanceof \Generator) {
                yield from $result;
            }
            if ($result === false || $response->isSent()) {
                return;
            }
        }

        $result = ($route['handler'])($request, $response, $route['params']);
        if ($result instanceof \Generator) {
            yield from $result;
        }
    }

    private function compile(): void {
        if ($this->compiled) {
            return;
        }
        $this->compiled = true;
        $this->compiledRegex = [];
        $this->compiledHandlers = [];

        $grouped = [];
        foreach ($this->routes as $route) {
            if (!str_contains($route['path'], '{')) {
                continue;
            }
            $grouped[$route['method']][] = $route;
        }

        foreach ($grouped as $method => $routes) {
            $patterns = [];
            foreach ($routes as $idx => $route) {
                $paramNames = [];
                $pattern = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use (&$paramNames) {
                    $paramNames[] = $m[1];
                    return "(?P<{$m[1]}>[^/]+)";
                }, $route['path']);

                $this->compiledHandlers[$method][$idx] = [
                    'handler' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'paramNames' => $paramNames,
                ];
                $patterns[] = "(?:{$pattern}(*MARK:{$idx}))";
            }
            $this->compiledRegex[$method] = '#^(?J)(?:' . implode('|', $patterns) . ')$#';
        }
    }

    public function getRoutes(): array {
        return $this->routes;
    }
}
