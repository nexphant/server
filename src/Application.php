<?php

namespace Nexph\Server;

use Nexph\Server\Attributes\Route;
use Nexph\Server\Middleware\Cors;
use Nexph\Server\Middleware\Security;
use Nexph\Runtime\OptimizeLoader;
use Nexph\Runtime\Runtime;
use Nexph\Runtime\RuntimeCache;
use Nexph\Support\Config;
use Nexph\Database\DB;

class Application
{
    private static ?self $instance = null;
    private HttpServer $server;
    private Router $router;
    private string $prefix = '';
    private array $routeMiddleware = [];
    private bool $notFoundRegistered = false;
    private bool $requestHandlerRegistered = false;
    private bool $booted = false;
    private string $basePath = '';
    private int $workers = 1;
    private bool $supervisor = false;
    private bool $autoWorkers = true;
    private static array $annotationRoutes = [];

    public function __construct(array $config = [], ?Router $router = null, ?HttpServer $server = null)
    {
        $this->basePath = $config['base_path'] ?? getcwd();
        $this->router = $router ?? new Router();
        $this->server = $server ?? new HttpServer($this->normalizeConfig($config));
        $this->autoWorkers = !isset($config['workers']);
        $this->workers = $config['workers'] ?? $this->detectCpuCount();
        $this->supervisor = $config['supervisor'] ?? ($this->workers > 1);
    }

    public static function create(array $config = []): self
    {
        self::$instance = new self($config);
        return self::$instance;
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function get(string $path, callable $handler, callable ...$middleware): self
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, callable ...$middleware): self
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable $handler, callable ...$middleware): self
    {
        return $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable $handler, callable ...$middleware): self
    {
        return $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, callable ...$middleware): self
    {
        return $this->add('DELETE', $path, $handler, $middleware);
    }

    public function options(string $path, callable $handler, callable ...$middleware): self
    {
        return $this->add('OPTIONS', $path, $handler, $middleware);
    }

    public function any(string $path, callable $handler, callable ...$middleware): self
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->add($method, $path, $handler, $middleware);
        }
        return $this;
    }

    public function group(string $prefix, callable $callback, callable ...$middleware): self
    {
        $group = new self(['base_path' => $this->basePath], $this->router, $this->server);
        $group->prefix = $this->joinPath($this->prefix, $prefix);
        $group->routeMiddleware = array_merge($this->routeMiddleware, $middleware);
        $callback($group);
        return $this;
    }

    public function use(callable $middleware): self
    {
        $this->server->use($middleware);
        return $this;
    }

    public function static(string $root, array $options = []): self
    {
        return $this->use(new StaticFiles($root, $options));
    }

    public function ws(string $path, callable $onMessage, ?callable $onOpen = null, ?callable $onClose = null): self
    {
        $this->server->onWebSocket($this->joinPath($this->prefix, $path), $onMessage, $onOpen, $onClose);
        return $this;
    }

    public function sse(string $path = '/events'): self
    {
        $this->get($path, function (ServerRequest $request, ServerResponse $response) {
            $this->server->startSse($request, $response, $request->getConnection());
        });
        return $this;
    }

    public function broadcastSse(string $path, mixed $data, ?string $event = 'message', string $channel = 'global'): int
    {
        return $this->server->broadcastSse($path, $data, $event, true, $channel);
    }

    public function runtime(string $prefix = '/api'): self
    {
        $prefix = '/' . trim($prefix, '/');
        $this->get($prefix . '/live', fn() => ['status' => 'ok', 'timestamp' => date('c')]);
        $this->get($prefix . '/ready', function () {
            $database = $this->databaseHealth();
            return [
                'status' => ($database['status'] ?? 'error') === 'ok' ? 'ok' : 'not_ready',
                'timestamp' => date('c'),
                'database' => $database,
            ];
        });
        $this->get($prefix . '/health', fn() => [
            'status' => 'ok',
            'timestamp' => date('c'),
            'stats' => $this->server->getStats(),
            'database' => $this->databaseStats(),
        ]);
        $this->get($prefix . '/metrics', function (ServerRequest $request, ServerResponse $response) {
            return $response
                ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
                ->body($this->server->getMetricsText() . $this->databaseMetricsText());
        });
        return $this;
    }

    public function annotations(string|object $controller): self
    {
        $object = is_object($controller) ? $controller : new $controller();
        $className = $object::class;
        $routes = self::$annotationRoutes[$className] ?? RuntimeCache::get('annotations:' . str_replace('\\', '.', $className));

        if (is_array($routes)) {
            self::$annotationRoutes[$className] = $routes;
            foreach ($routes as $route) {
                $this->add($route['method'], $route['path'], [$object, $route['handler']], $route['middleware']);
            }
            return $this;
        }

        $class = new \ReflectionClass($object);
        $prefix = '';
        $classRoute = $class->getAttributes(Route::class)[0] ?? null;
        if ($classRoute !== null) {
            $prefix = $classRoute->newInstance()->path;
        }

        $routes = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $route = $attribute->newInstance();
                $routes[] = [
                    'method' => $route->method,
                    'path' => $this->joinPath($prefix, $route->path),
                    'handler' => $method->getName(),
                    'middleware' => $route->middleware,
                ];
            }
        }

        self::$annotationRoutes[$className] = $routes;
        RuntimeCache::set('annotations:' . str_replace('\\', '.', $className), $routes, 0);
        foreach ($routes as $route) {
            $this->add($route['method'], $route['path'], [$object, $route['handler']], $route['middleware']);
        }

        return $this;
    }

    public function workers(int $count): self
    {
        $this->workers = max(1, $count);
        $this->autoWorkers = false;
        $this->supervisor = $this->workers > 1;
        return $this;
    }

    public function supervisor(bool $enabled): self
    {
        $this->supervisor = $enabled;
        return $this;
    }

    public function listen(int $port = 8080, string $host = '0.0.0.0'): void
    {
        $this->boot();
        $this->server->setAddress($host, $port);
        $this->registerNotFound();
        $this->registerRequestHandler();

        if ($this->supervisor && $this->workers > 1 && function_exists('pcntl_fork')) {
            $this->runWithSupervisor($host, $port);
            return;
        }

        $this->server->start();
    }

    public function getServer(): HttpServer
    {
        return $this->server;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        Runtime::configure($this->server->getConfig());

        // Warmup optimizations
        OptimizeLoader::warmup([
            HttpServer::class,
            Router::class,
            AsyncIO::class,
            StaticFiles::class,
            Cors::class,
            Security::class,
            Config::class,
        ]);

        // Setup async components
        AsyncIO::setLoop($this->server->getLoop());

        // Load config if exists
        $envFile = $this->basePath . '/.env';
        $configFile = $this->basePath . '/config/app.php';
        if (is_file($envFile)) {
            Config::loadEnv($envFile);
        }
        if (is_file($configFile)) {
            Config::load($configFile);
        }

        // Setup database if configured
        if (class_exists(AsyncDatabase::class)) {
            AsyncDatabase::setLoop($this->server->getLoop());
            if ($dbConfig = Config::get('db')) {
                AsyncDatabase::connect($dbConfig);
            }
        }
    }

    private function runWithSupervisor(string $host, int $port): void
    {
        $workers = $this->workers;
        $children = [];
        $stopping = false;
        $gracefulTimeout = 30;
        $deadline = 0.0;

        $spawn = function (int $workerId) use (&$children, $workers): void {
            $pid = pcntl_fork();
            if ($pid === -1) {
                return;
            }
            if ($pid === 0) {
                \Nexph\Database\DB::reconnect();
                $this->server->setWorkerInfo($workerId, $workers);
                $this->server->start();
                exit(0);
            }
            $children[$pid] = $workerId;
        };

        for ($i = 1; $i <= $workers; $i++) {
            $spawn($i);
        }

        pcntl_async_signals(true);
        $shutdown = function () use (&$stopping, &$deadline, &$children, $gracefulTimeout): void {
            $stopping = true;
            $deadline = microtime(true) + $gracefulTimeout;
            foreach (array_keys($children) as $pid) {
                @posix_kill($pid, defined('SIGUSR1') ? SIGUSR1 : SIGTERM);
            }
        };
        pcntl_signal(SIGTERM, $shutdown);
        pcntl_signal(SIGINT, $shutdown);

        ServerTUI::setMainProcess(true);
        ServerTUI::supervisorStarted($workers);

        while ($children !== []) {
            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                $workerId = $children[$pid] ?? null;
                unset($children[$pid]);
                if (!$stopping && $workerId !== null) {
                    $spawn($workerId);
                }
            }

            if ($stopping && $deadline > 0 && microtime(true) >= $deadline) {
                foreach (array_keys($children) as $pid) {
                    @posix_kill($pid, SIGTERM);
                }
                $deadline = 0.0;
            }

            usleep(100000);
        }
    }

    private function add(string $method, string $path, callable $handler, array $middleware = []): self
    {
        $this->router->add(
            $method,
            $this->joinPath($this->prefix, $path),
            $this->wrap($handler),
            array_merge($this->routeMiddleware, $middleware)
        );
        return $this;
    }

    private function wrap(callable $handler): callable
    {
        return function (ServerRequest $request, ServerResponse $response, array $params = []) use ($handler) {
            $result = $handler($request, $response, $params);
            if ($result instanceof \Generator) {
                return $result;
            }
            if ($result instanceof ServerResponse || $result === null) {
                return;
            }
            if (is_array($result) || is_object($result)) {
                $response->json($result);
                return;
            }
            $response->text((string) $result);
        };
    }

    private function registerNotFound(): void
    {
        if ($this->notFoundRegistered) {
            return;
        }
        $this->notFoundRegistered = true;
        $this->router->any('/{path}', fn(ServerRequest $request, ServerResponse $response) => $response->notFound("Route not found: {$request->method} {$request->path}"));
    }

    private function registerRequestHandler(): void
    {
        if ($this->requestHandlerRegistered) {
            return;
        }
        $this->requestHandlerRegistered = true;
        $this->server->onRequest(fn(ServerRequest $request, ServerResponse $response) => $this->router->dispatch($request, $response));
    }

    private function databaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::query('SELECT 1');
            return [
                'status' => 'ok',
                'latency_ms' => round((microtime(true) - $start) * 1000, 3),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function databaseStats(): array
    {
        if (class_exists(AsyncDatabase::class)) {
            return AsyncDatabase::stats();
        }
        return DB::stats();
    }

    private function databaseMetricsText(): string
    {
        $db = $this->databaseStats();
        return '# HELP nexph_database_queries_total Total database queries.' . "\n"
            . '# TYPE nexph_database_queries_total counter' . "\n"
            . 'nexph_database_queries_total ' . (int) ($db['queries'] ?? 0) . "\n"
            . '# HELP nexph_database_errors_total Total database errors.' . "\n"
            . '# TYPE nexph_database_errors_total counter' . "\n"
            . 'nexph_database_errors_total ' . (int) ($db['errors'] ?? 0) . "\n"
            . '# HELP nexph_database_query_avg_ms Average database query duration.' . "\n"
            . '# TYPE nexph_database_query_avg_ms gauge' . "\n"
            . 'nexph_database_query_avg_ms ' . sprintf('%.6F', (float) ($db['avg_ms'] ?? 0)) . "\n"
            . '# HELP nexph_database_query_max_ms Max database query duration.' . "\n"
            . '# TYPE nexph_database_query_max_ms gauge' . "\n"
            . 'nexph_database_query_max_ms ' . sprintf('%.6F', (float) ($db['max_ms'] ?? 0)) . "\n"
            . '# HELP nexph_database_slow_queries_total Slow database queries.' . "\n"
            . '# TYPE nexph_database_slow_queries_total counter' . "\n"
            . 'nexph_database_slow_queries_total ' . (int) ($db['slow_queries'] ?? 0) . "\n"
            . '# HELP nexph_database_statement_hits_total Prepared statement cache hits.' . "\n"
            . '# TYPE nexph_database_statement_hits_total counter' . "\n"
            . 'nexph_database_statement_hits_total ' . (int) ($db['statement_hits'] ?? 0) . "\n"
            . '# HELP nexph_database_statement_misses_total Prepared statement cache misses.' . "\n"
            . '# TYPE nexph_database_statement_misses_total counter' . "\n"
            . 'nexph_database_statement_misses_total ' . (int) ($db['statement_misses'] ?? 0) . "\n";
    }

    private function normalizeConfig(array $config): array
    {
        $fdLimit = $this->detectFileDescriptorLimit();
        $cpuCount = $this->detectCpuCount();
        $workers = $config['workers'] ?? $cpuCount;
        $maxConnections = $config['max_connections'] ?? $this->autoMaxConnections($fdLimit, $workers);
        $backlog = $config['backlog'] ?? $this->autoBacklog($maxConnections, $workers);

        return array_merge([
            'host' => '0.0.0.0',
            'port' => 8080,
            'max_connections' => $maxConnections,
            'backlog' => $backlog,
            'worker_count' => $workers,
            'keep_alive_timeout' => 30,
            'max_deferred' => 100000,
            'max_requests' => 10000,
            'max_write_buffer_size' => 1024 * 1024,
            'object_tracking' => false,
            'pool_safety' => false,
            'memory_pressure_threshold' => 0.85,
            'memory_hard_pressure_threshold' => 0.95,
            'http_route_latency_sample_limit' => 0,
            'graceful_shutdown_timeout' => 30,
            'websocket_timeout' => 300,
            'websocket_ping_interval' => 30,
            'websocket_pong_timeout' => 90,
            'websocket_bus_max_bytes' => 8 * 1024 * 1024,
            'websocket_broadcast_batch_size' => 250,
            'websocket_backpressure_policy' => 'close',
            'websocket_backpressure_soft_limit' => 524288,
            'websocket_max_frame_size' => 1048576,
            'websocket_max_read_buffer_size' => 2097152,
            'websocket_bus' => 'file',
            'websocket_bus_single_worker' => false,
            'websocket_redis_url' => 'redis://127.0.0.1:6379/0',
            'websocket_redis_channel' => 'nexph:websocket',
            'sse_heartbeat_interval' => 15,
            'sse_timeout' => 300,
            'sse_bus' => 'file',
            'sse_bus_single_worker' => false,
            'sse_bus_max_bytes' => 8 * 1024 * 1024,
            'sse_redis_url' => 'redis://127.0.0.1:6379/0',
            'sse_redis_channel' => 'nexph:sse',
            'sse_auth_token' => '',
            'sse_replay_limit' => 1024,
            'debug' => false,
        ], $config);
    }

    private function detectCpuCount(): int
    {
        $count = (int) @shell_exec('nproc 2>/dev/null');
        if ($count > 0) {
            return $count;
        }
        $count = (int) @shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null');
        return $count > 0 ? $count : 1;
    }

    private function detectFileDescriptorLimit(): int
    {
        $limit = trim((string) @shell_exec('sh -c "ulimit -n" 2>/dev/null'));
        if ($limit === 'unlimited') {
            return 1048576;
        }
        $value = (int) $limit;
        return $value > 0 ? $value : 1024;
    }

    private function autoMaxConnections(int $fdLimit, int $workers): int
    {
        $usable = max(128, $fdLimit - 256);
        $perWorker = (int) floor(($usable * 0.70) / max(1, $workers));
        return max(1000, min(5000, $perWorker));
    }

    private function autoBacklog(int $maxConnections, int $workers): int
    {
        $somaxconn = 4096;
        if (is_readable('/proc/sys/net/core/somaxconn')) {
            $value = (int) trim((string) @file_get_contents('/proc/sys/net/core/somaxconn'));
            if ($value > 0) {
                $somaxconn = $value;
            }
        }
        $desired = max(4096, min(10000, $maxConnections * max(1, $workers)));
        return min($desired, $somaxconn);
    }

    private function joinPath(string $prefix, string $path): string
    {
        $prefix = '/' . trim($prefix, '/');
        $path = '/' . trim($path, '/');
        $joined = rtrim($prefix, '/') . $path;
        return $joined === '' ? '/' : $joined;
    }
}
