<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Server;

class Route
{
    public function __construct(
        private Router $router,
        private string $method,
        private string $path
    ) {
    }

    public function fast(): self
    {
        $this->router->markFast($this->method, $this->path);
        return $this;
    }

    public function cacheJson(): self
    {
        $this->router->markCacheJson($this->method, $this->path);
        return $this;
    }
}

class Router
{
    private array $routes = [];
    private array $exactRoutes = [];
    private array $fastRoutes = [];
    private array $middleware = [];
    private string $prefix = '';
    private bool $compiled = false;
    private array $compiledRegex = [];
    private array $compiledHandlers = [];
    private ?Route $lastRoute = null;

    public function get(string $path, callable $handler, array $middleware = []): Route
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): Route
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable $handler, array $middleware = []): Route
    {
        return $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable $handler, array $middleware = []): Route
    {
        return $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, array $middleware = []): Route
    {
        return $this->add('DELETE', $path, $handler, $middleware);
    }

    public function options(string $path, callable $handler, array $middleware = []): Route
    {
        return $this->add('OPTIONS', $path, $handler, $middleware);
    }

    public function any(string $path, callable $handler, array $middleware = []): Route
    {
        $last = null;
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $last = $this->add($method, $path, $handler, $middleware);
        }
        return $last;
    }

    public function add(string $method, string $path, callable $handler, array $middleware = []): Route
    {
        $method = strtoupper($method);
        $fullPath = $this->prefix . $path;

        $route = [
            'method' => $method,
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => array_merge($this->middleware, $middleware),
            'fast' => false,
            'cache_json' => false,
        ];
        $this->routes[] = $route;
        $this->compiled = false;

        if (!str_contains($fullPath, '{')) {
            $this->exactRoutes[$method][$fullPath] = $route;
        }

        $this->lastRoute = new Route($this, $method, $fullPath);
        return $this->lastRoute;
    }

    public function markFast(string $method, string $path): void
    {
        if (isset($this->exactRoutes[$method][$path])) {
            $this->exactRoutes[$method][$path]['fast'] = true;
            $this->fastRoutes[$method][$path] = $this->exactRoutes[$method][$path];
        }
    }

    public function markCacheJson(string $method, string $path): void
    {
        if (isset($this->exactRoutes[$method][$path])) {
            $this->exactRoutes[$method][$path]['cache_json'] = true;
            $route = $this->exactRoutes[$method][$path];
            if (is_callable($route['handler']) && empty($route['middleware'])) {
                try {
                    $dummy = new class extends ServerRequest {
                        public function __call($name, $args)
                        {
                            return $args[0] ?? null;
                        }
                        public function __get($name)
                        {
                            return '';
                        }
                        public function getAttribute(string $name, mixed $default = null): mixed
                        {
                            return $default;
                        }
                    };
                    $dummyResp = new class extends ServerResponse {
                        public function __call($name, $args)
                        {
                            return $this;
                        }
                    };
                    $result = ($route['handler'])($dummy, $dummyResp, []);
                    if (is_array($result)) {
                        $json = \Nexph\Runtime\JsonSerializer::encode($result);
                        $prebuilt = \Nexph\Server\RawResponse::json($json);
                        $this->exactRoutes[$method][$path]['prebuilt'] = $prebuilt;
                        if (isset($this->fastRoutes[$method][$path])) {
                            $this->fastRoutes[$method][$path]['prebuilt'] = $prebuilt;
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
        }
    }

    public function group(string $prefix, callable $callback): self
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->middleware;

        $this->prefix .= $prefix;
        $callback($this);

        $this->prefix = $previousPrefix;
        $this->middleware = $previousMiddleware;

        return $this;
    }

    public function middleware(callable ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    public function match(string $method, string $path): ?array
    {
        if (isset($this->exactRoutes[$method][$path])) {
            $route = $this->exactRoutes[$method][$path];
            return [
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
                'params' => [],
                'fast' => $route['fast'] ?? false,
                'cache_json' => $route['cache_json'] ?? false,
            ];
        }

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
                    'fast' => false,
                    'cache_json' => false,
                ];
            }
        }

        return null;
    }

    public function dispatch(ServerRequest $request, ServerResponse $response): \Generator
    {
        return $this->dispatchAsync($request, $response);
    }

    public function dispatchSync(ServerRequest $request, ServerResponse $response): void
    {
        if (isset($this->fastRoutes[$request->method][$request->path])) {
            $route = $this->fastRoutes[$request->method][$request->path];
            if (isset($route['prebuilt'])) {
                $response->rawHttp($route['prebuilt']->http);
                return;
            }
            if (empty($route['middleware'])) {
                $result = ($route['handler'])($request, $response, []);
                if ($result !== null && !($result instanceof \Generator)) {
                    return;
                }
                return;
            }
        }

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

    public function dispatchAsync(ServerRequest $request, ServerResponse $response): \Generator
    {
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

    private function compile(): void
    {
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

    public function getRoutes(): array
    {
        return $this->routes;
    }
}
