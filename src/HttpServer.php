<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server;

use Nexphant\Server\Server\Connection;
use Nexphant\Server\Server\BufferPool;
use Nexphant\Server\Server\NativeFastLoop;
use Nexphant\Server\Server\Native\NativeOpsFactory;
use Nexphant\Runtime\MemoryMonitor;
use Nexphant\Runtime\ResponseCache;
use Nexphant\Runtime\Adaptive\AdaptiveRuntime;
use Nexphant\Runtime\Adaptive\SharedWorkerTable;

class HttpServer
{
    private EventLoop $loop;
    private array $config = [];
    private $serverSocket;
    private array $connections = [];
    private int $connectionId = 0;
    private array $middleware = [];
    private array $webSocketHandlers = [];
    private $requestHandler;
    private MemoryMonitor $memoryMonitor;
    private ObjectTracker $objectTracker;
    private ObjectPool $responsePool;
    private ObjectPool $requestPool;
    private BufferPool $bufferPool;
    private FastPathRegistry $fastPath;
    private Server\FastPathEngine $fastEngine;
    private ?\Nexphant\Server\Socket\SocketDriverInterface $socketDriver = null;
    private bool $quiet = false;
    private ?AdaptiveRuntime $adaptive = null;
    private ?\Nexphant\Lifecycle\Owner $workerOwner = null;

    // Config
    private string $host = '0.0.0.0';
    private int $port = 8080;
    private int $maxConnections = 500;
    private int $maxAcceptPerTick = 32;
    private int $keepAliveTimeout = 2;
    private int $maxRequestsPerConnection = 100;
    private int $maxRequestSize = 10 * 1024 * 1024; // 10MB
    private int $maxWriteBufferSize = 1024 * 1024;
    private int $webSocketTimeout = 300;
    private int $webSocketPingInterval = 30;
    private int $webSocketPongTimeout = 90;
    private int $webSocketBusMaxBytes = 8 * 1024 * 1024;
    private int $webSocketBroadcastBatchSize = 250;
    private int $webSocketBackpressureSoftLimit = 524288;
    private int $webSocketMaxFrameSize = 1048576;
    private int $webSocketMaxReadBufferSize = 2097152;
    private string $webSocketBackpressurePolicy = 'close';
    private int $sseHeartbeatInterval = 15;
    private int $sseTimeout = 300;
    private int $memoryLimit = 256 * 1024 * 1024; // 256MB
    private float $memoryPressureThreshold = 0.85;
    private float $memoryHardPressureThreshold = 0.95;
    private int $gracefulShutdownTimeout = 30;
    private int $backlog = 4096;
    private int $workerId = 1;
    private int $workerCount = 1;
    private string $statsDir = '';
    private string $webSocketBusFile = '';
    private string $sseBusFile = '';
    private string $webSocketBusType = 'file';
    private string $sseBusType = 'file';
    private bool $webSocketBusSingleWorker = false;
    private bool $sseBusSingleWorker = false;
    private int $sseBusMaxBytes = 8 * 1024 * 1024;
    private string $sseAuthToken = '';
    private int $sseReplayLimit = 1024;
    private int $sseEventSequence = 0;
    private string $lastSseEventId = '';
    private int $webSocketBusOffset = 0;
    private int $sseBusOffset = 0;
    private array $webSocketSeenEvents = [];
    private array $sseSeenEvents = [];
    private array $sseReplayBuffer = [];
    private array $webSocketRooms = [];
    private array $webSocketConnectionRooms = [];
    private ?WebSocketRedisBus $webSocketRedisBus = null;
    private ?WebSocketRedisBus $sseRedisBus = null;
    private bool $debug = false;
    private bool $accepting = true;
    private bool $draining = false;
    private bool $shuttingDown = false;
    private bool $objectTrackingEnabled = false;
    private bool $poolSafetyEnabled = false;
    private bool $hotPathCacheEnabled = false;
    private bool $runtimeSafetyEnabled = true;
    private bool $routeLatencyEnabled = false;
    private bool $histogramEnabled = true;
    private int $metricsSampleRate = 1;
    private int $metricsSampleCounter = 0;
    private float $drainStartedAt = 0.0;

    // Stats
    private int $totalRequests = 0;
    private int $totalConnections = 0;
    private int $totalWebSockets = 0;
    private int $webSocketPingsSent = 0;
    private int $webSocketPongsReceived = 0;
    private int $webSocketHeartbeatTimeouts = 0;
    private int $webSocketIdleCloses = 0;
    private int $webSocketBroadcasts = 0;
    private int $webSocketBroadcastDeliveries = 0;
    private int $webSocketBroadcastBatches = 0;
    private int $webSocketPendingBroadcastBatches = 0;
    private int $webSocketBackpressureSkips = 0;
    private int $webSocketBackpressureCloses = 0;
    private int $webSocketReadLimitCloses = 0;
    private int $webSocketFrameLimitCloses = 0;
    private int $totalSseConnections = 0;
    private int $sseEventsSent = 0;
    private int $sseHeartbeatsSent = 0;
    private int $sseBackpressureCloses = 0;
    private int $sseAuthFailures = 0;
    private int $sseReplayRequests = 0;
    private int $sseReplayedEvents = 0;
    private int $sseReplayExpired = 0;
    private int $sseBroadcasts = 0;
    private int $sseLocalDeliveries = 0;
    private int $sseBusPublished = 0;
    private int $sseBusDeliveries = 0;
    private int $activeRequests = 0;
    private array $httpStatusCounts = [];
    private array $httpRouteCounts = [];
    private array $httpLatencyBuckets = [];
    private array $httpRouteLatencySamples = [];
    private int $httpLatencyCount = 0;
    private float $httpLatencySumMs = 0.0;
    private float $httpLatencyMaxMs = 0.0;
    private float $startTime = 0.0;
    private float $loopLagMs = 0.0;
    private float $loopLagMaxMs = 0.0;
    private string $memoryPressureState = 'normal';
    private int $memoryPressureEvents = 0;
    private int $memoryPressureRejected = 0;
    private int $memoryPressureClosed = 0;
    private int $httpRouteLatencySampleLimit = 0;
    private const LATENCY_BUCKETS_MS = [1, 5, 10, 25, 50, 75, 100, 250, 500, 1000, 2500, 5000];

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->host = $config['host'] ?? '0.0.0.0';
        $this->port = $config['port'] ?? 8080;
        $this->maxConnections = $config['max_connections'] ?? 500;
        $this->maxAcceptPerTick = $config['max_accept_per_tick'] ?? 32;
        $this->quiet = $config['quiet'] ?? false;
        $this->keepAliveTimeout = $config['keep_alive_timeout'] ?? 2;
        $this->maxRequestsPerConnection = $config['max_requests'] ?? 100;
        $this->maxRequestSize = $config['max_request_size'] ?? 10 * 1024 * 1024;
        $this->maxWriteBufferSize = $config['max_write_buffer_size'] ?? 1024 * 1024;
        $this->webSocketTimeout = $config['websocket_timeout'] ?? 300;
        $this->webSocketPingInterval = $config['websocket_ping_interval'] ?? 30;
        $this->webSocketPongTimeout = $config['websocket_pong_timeout'] ?? 90;
        $this->webSocketBusMaxBytes = $config['websocket_bus_max_bytes'] ?? 8 * 1024 * 1024;
        $this->webSocketBroadcastBatchSize = max(1, (int) ($config['websocket_broadcast_batch_size'] ?? 250));
        $this->webSocketBackpressureSoftLimit = max(1024, (int) ($config['websocket_backpressure_soft_limit'] ?? 524288));
        $this->webSocketMaxFrameSize = max(125, (int) ($config['websocket_max_frame_size'] ?? 1048576));
        $this->webSocketMaxReadBufferSize = max($this->webSocketMaxFrameSize + 14, (int) ($config['websocket_max_read_buffer_size'] ?? 2097152));
        $policy = strtolower((string) ($config['websocket_backpressure_policy'] ?? 'close'));
        $this->webSocketBackpressurePolicy = in_array($policy, ['close', 'skip'], true) ? $policy : 'close';
        $this->sseHeartbeatInterval = max(0, (int) ($config['sse_heartbeat_interval'] ?? 15));
        $this->sseTimeout = max(1, (int) ($config['sse_timeout'] ?? 300));
        $this->memoryLimit = $config['memory_limit'] ?? 256 * 1024 * 1024;
        $this->memoryPressureThreshold = min(0.99, max(0.10, (float) ($config['memory_pressure_threshold'] ?? 0.85)));
        $this->memoryHardPressureThreshold = min(0.999, max($this->memoryPressureThreshold, (float) ($config['memory_hard_pressure_threshold'] ?? 0.95)));
        $this->httpRouteLatencySampleLimit = max(0, (int) ($config['http_route_latency_sample_limit'] ?? 0));
        $this->gracefulShutdownTimeout = max(1, (int) ($config['graceful_shutdown_timeout'] ?? 30));
        $this->backlog = $config['backlog'] ?? 4096;
        $this->workerId = $config['worker_id'] ?? 1;
        $this->workerCount = $config['worker_count'] ?? 1;
        $this->statsDir = $config['stats_dir'] ?? (sys_get_temp_dir() . '/nexphant-http-' . $this->port);
        $this->webSocketBusFile = $this->statsDir . '/websocket-bus.log';
        $this->sseBusFile = $this->statsDir . '/sse-bus.log';
        $busType = $config['websocket_bus'] ?? 'file';
        $this->webSocketBusType = in_array($busType, ['file', 'redis', 'off'], true) ? $busType : 'file';
        $sseBusType = $config['sse_bus'] ?? $this->webSocketBusType;
        $this->sseBusType = in_array($sseBusType, ['file', 'redis', 'off'], true) ? $sseBusType : 'file';
        $this->webSocketBusSingleWorker = (bool) ($config['websocket_bus_single_worker'] ?? false);
        $this->sseBusSingleWorker = (bool) ($config['sse_bus_single_worker'] ?? false);
        $this->sseBusMaxBytes = max(65536, (int) ($config['sse_bus_max_bytes'] ?? $this->webSocketBusMaxBytes));
        $this->sseAuthToken = (string) ($config['sse_auth_token'] ?? '');
        $this->sseReplayLimit = max(0, (int) ($config['sse_replay_limit'] ?? 1024));
        $this->debug = $config['debug'] ?? false;
        $this->objectTrackingEnabled = (bool) ($config['object_tracking'] ?? false);
        $this->poolSafetyEnabled = (bool) ($config['pool_safety'] ?? false);
        $this->hotPathCacheEnabled = (float) ($config['hot_path_cache_ttl'] ?? 0.0) > 0.0;
        $this->runtimeSafetyEnabled = (bool) ($config['runtime_safety'] ?? !($config['performance_mode'] ?? false));
        if (isset($config['runtime_discipline'])) {
            $this->runtimeSafetyEnabled = (bool) $config['runtime_discipline'];
        }
        $this->routeLatencyEnabled = $this->runtimeSafetyEnabled && (bool) ($config['route_latency'] ?? true);
        $this->histogramEnabled = $this->runtimeSafetyEnabled && (bool) ($config['histogram'] ?? true);
        $this->metricsSampleRate = max(1, (int) ($config['metrics_sample_rate'] ?? ($this->runtimeSafetyEnabled ? 1 : 100)));

        $backend = \Nexphant\Runtime\EventLoop\EventLoopFactory::create($config['event_loop'] ?? 'auto');
        // $backendName = (new \ReflectionClass($backend))->getShortName();
        // if (!($config['quiet'] ?? false) && ($config['worker_id'] ?? 1) === 1) {
        //     error_log("Event Loop: $backendName");
        // }

        $this->loop = new EventLoop($backend);
        $this->loop->setMaxDeferred((int) ($config['max_deferred'] ?? 100000));
        $this->loop->setFairnessLimits(
            (int) ($config['max_read_callbacks_per_tick'] ?? 512),
            (int) ($config['max_write_callbacks_per_tick'] ?? 512),
            (int) ($config['max_deferred_per_tick'] ?? 512)
        );
        $this->memoryMonitor = new MemoryMonitor();
        $this->objectTracker = new ObjectTracker($this->objectTrackingEnabled);
        $this->responsePool = new ObjectPool(
            fn() => new Response(),
            $config['response_pool_size'] ?? 2048,
            fn(Response $response) => $response->reset(),
            'response',
            $this->objectTracker,
            $this->poolSafetyEnabled
        );
        $this->requestPool = new ObjectPool(
            fn() => new Request(),
            $config['request_pool_size'] ?? 2048,
            fn(Request $request) => $request->reset(),
            'request',
            $this->objectTracker,
            $this->poolSafetyEnabled
        );
        $this->bufferPool = new BufferPool(
            $config['buffer_pool_size'] ?? 4096,
            $this->objectTracker,
            $this->poolSafetyEnabled
        );
        foreach (self::LATENCY_BUCKETS_MS as $bucket) {
            $this->httpLatencyBuckets[(string) $bucket] = 0;
        }
        $this->httpLatencyBuckets['+Inf'] = 0;
        $this->fastPath = new FastPathRegistry();
        $this->fastEngine = new Server\FastPathEngine(NativeOpsFactory::create($config));
        Coroutine::setLoop($this->loop);

        // Adaptive runtime primitives
        $sharedTable = null;
        if (($config['adaptive_shared_table'] ?? true) && ($this->workerCount > 1)) {
            try {
                $sharedTable = new SharedWorkerTable(
                    (int) ($config['adaptive_shm_key'] ?? 0),
                    (int) ($config['worker_count'] ?? 64)
                );
            } catch (\Throwable) {
                $sharedTable = null;
            }
        }
        $adaptiveAcceptEnabled = (bool) ($config['adaptive_accept'] ?? false);
        $this->adaptive = AdaptiveRuntime::init($this->workerId, [
            'max_connections' => $this->maxConnections,
            'max_requests' => (int) ($config['adaptive_max_active_requests'] ?? 1000),
            'max_pending_writes' => (int) ($config['adaptive_max_pending_writes'] ?? 200),
            'max_tick_ms' => (float) ($config['adaptive_max_tick_ms'] ?? 50.0),
            'max_accept_per_tick' => $this->maxAcceptPerTick,
            'max_reads_per_connection_tick' => (int) ($config['max_reads_per_connection_tick'] ?? 8),
            'adaptive_accept_enabled' => $adaptiveAcceptEnabled,
            'max_writes_per_connection_tick' => (int) ($config['max_writes_per_connection_tick'] ?? 8),
        ], $sharedTable);
    }

    public function use(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function onRequest(callable $handler): self
    {
        $this->requestHandler = $handler;
        return $this;
    }

    public function onWebSocket(string $path, callable $onMessage, ?callable $onOpen = null, ?callable $onClose = null): self
    {
        $this->webSocketHandlers[$path] = [
            'message' => $onMessage,
            'open' => $onOpen,
            'close' => $onClose,
        ];
        return $this;
    }

    public function setWorkerInfo(int $workerId, int $workerCount): void
    {
        $this->workerId = max(1, $workerId);
        $this->workerCount = max(1, $workerCount);
        $this->config['worker_id'] = $this->workerId;
        $this->config['worker_count'] = $this->workerCount;
        $this->webSocketBusOffset = is_file($this->webSocketBusFile) ? (int) filesize($this->webSocketBusFile) : 0;
        $this->sseBusOffset = is_file($this->sseBusFile) ? (int) filesize($this->sseBusFile) : 0;
        ServerTUI::setWorkerInfo($workerId, $workerCount);
    }

    public function configure(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        $this->host = $this->config['host'] ?? $this->host;
        $this->port = $this->config['port'] ?? $this->port;
        $this->statsDir = $this->config['stats_dir'] ?? (sys_get_temp_dir() . '/nexphant-http-' . $this->port);
        $this->webSocketBusFile = $this->statsDir . '/websocket-bus.log';
        $this->sseBusFile = $this->statsDir . '/sse-bus.log';
        $this->maxConnections = (int) ($this->config['max_connections'] ?? $this->maxConnections);
        $this->maxAcceptPerTick = (int) ($this->config['max_accept_per_tick'] ?? $this->maxAcceptPerTick);
        $this->quiet = (bool) ($this->config['quiet'] ?? $this->quiet);
        $this->keepAliveTimeout = (int) ($this->config['keep_alive_timeout'] ?? $this->keepAliveTimeout);
        $this->maxRequestsPerConnection = (int) ($this->config['max_requests'] ?? $this->maxRequestsPerConnection);
        $this->maxRequestSize = (int) ($this->config['max_request_size'] ?? $this->maxRequestSize);
        $this->maxWriteBufferSize = (int) ($this->config['max_write_buffer_size'] ?? $this->maxWriteBufferSize);
        $this->backlog = (int) ($this->config['backlog'] ?? $this->backlog);
        $this->workerId = (int) ($this->config['worker_id'] ?? $this->workerId);
        $this->workerCount = (int) ($this->config['worker_count'] ?? $this->workerCount);
        $this->runtimeSafetyEnabled = (bool) ($this->config['runtime_safety'] ?? !($this->config['performance_mode'] ?? false));
        if (isset($this->config['runtime_discipline'])) {
            $this->runtimeSafetyEnabled = (bool) $this->config['runtime_discipline'];
        }
        $this->routeLatencyEnabled = $this->runtimeSafetyEnabled && (bool) ($this->config['route_latency'] ?? true);
        $this->histogramEnabled = $this->runtimeSafetyEnabled && (bool) ($this->config['histogram'] ?? true);
        $this->metricsSampleRate = max(1, (int) ($this->config['metrics_sample_rate'] ?? ($this->runtimeSafetyEnabled ? 1 : 100)));
        $this->loop->setMaxDeferred((int) ($this->config['max_deferred'] ?? 100000));
        $this->loop->setFairnessLimits(
            (int) ($this->config['max_read_callbacks_per_tick'] ?? 512),
            (int) ($this->config['max_write_callbacks_per_tick'] ?? 512),
            (int) ($this->config['max_deferred_per_tick'] ?? 512)
        );
        return $this;
    }

    public function setAddress(string $host, int $port): self
    {
        $this->host = $host;
        $this->port = $port;
        $this->config['host'] = $host;
        $this->config['port'] = $port;
        return $this;
    }

    public function start(): void
    {
        ServerTUI::setEnabled(!$this->quiet);
        $this->startTime = microtime(true);
        $this->workerOwner ??= class_exists('\Nexphant\Lifecycle\Lifecycle') && $this->runtimeSafetyEnabled
            ? \Nexphant\Lifecycle\Lifecycle::worker()
            : null;
        if ($this->shouldUseDirectFastLoop()) {
            $this->createServer(false);
            ServerTUI::serverStarted($this->host, $this->port);
            $this->runDirectFastLoop();
            return;
        }

        $this->prepareStatsDir();
        $this->createServer(true);
        $this->setupSignals();
        $this->setupTimers();
        $this->publishStats();

        ServerTUI::serverStarted($this->host, $this->port);

        $this->loop->run();
    }

    private function createServer(bool $attachReader = true): void
    {
        $this->socketDriver = \Nexphant\Server\Socket\SocketDriverFactory::create(
            $this->config['socket_driver'] ?? 'auto',
            ['reuse_port' => $this->config['reuse_port'] ?? (($this->config['profile'] ?? '') === 'low_latency' ? false : true)]
        );
        // $driverName = (new \ReflectionClass($this->socketDriver))->getShortName();
        // if (!$this->quiet && $this->workerId === 1) {
        //     error_log("Socket Driver: $driverName");
        // }

        $this->serverSocket = $this->socketDriver->listen($this->host, $this->port);

        if (!$this->serverSocket) {
            throw new \RuntimeException("Failed to start server on {$this->host}:{$this->port}");
        }

        if (!$attachReader) {
            return;
        }

        $this->loop->addReader($this->serverSocket, function ($socket) {
            $this->acceptConnections();
        });
    }

    private function shouldUseDirectFastLoop(): bool
    {
        return !$this->runtimeSafetyEnabled &&
            ($this->config['direct_fast_loop'] ?? false) &&
            NativeFastLoop::supported() &&
            empty($this->middleware) &&
            empty($this->webSocketHandlers) &&
            $this->fastEngine->hasRoutes() &&
            in_array((string) ($this->config['socket_driver'] ?? 'auto'), ['auto', 'native'], true);
    }

    private function runDirectFastLoop(): void
    {
        (new NativeFastLoop(
            $this->socketDriver,
            $this->serverSocket,
            $this->fastEngine,
            $this->maxConnections,
            $this->maxAcceptPerTick,
            $this->maxRequestsPerConnection,
            native: $this->fastEngine->native()
        ))->run();
    }

    private function setupSignals(): void
    {
        $this->loop->addSignal(SIGINT, fn() => $this->shutdown());
        $this->loop->addSignal(SIGTERM, fn() => $this->shutdown());
        if (defined('SIGUSR1')) {
            $this->loop->addSignal(SIGUSR1, fn() => $this->gracefulShutdown());
        }
    }

    private function setupTimers(): void
    {
        $profile = $this->config['runtime_mode'] ?? 'balanced';
        $features = $this->config['runtime_features'] ?? [];
        $statsEnabled = $features['stats_file_writes'] ?? true;
        $metricsEnabled = $features['metrics'] ?? true;

        $lagInterval = 0.5;
        $lastTick = microtime(true);
        $this->loop->addTimer($lagInterval, function () use (&$lastTick, $lagInterval) {
            $now = microtime(true);
            $lag = max(0.0, ($now - $lastTick - $lagInterval) * 1000);
            $this->loopLagMs = $this->loopLagMs === 0.0 ? $lag : ($this->loopLagMs * 0.8) + ($lag * 0.2);
            $this->loopLagMaxMs = max($this->loopLagMaxMs, $lag);
            $lastTick = $now;

            if ($this->adaptive !== null) {
                $this->adaptive->stats->loopTickDuration = $this->loopLagMs;
                $this->adaptive->fairness->reset();
            }
        }, periodic: true);

        if (!$this->quiet && ServerTUI::isEnabled()) {
            $this->loop->addTimer(1.0, function () {
                ServerTUI::tick();
            }, periodic: true);
        }

        $this->loop->addTimer(1.0, function () {
            $this->cleanupConnections();
        }, periodic: true);

        if (!empty($this->webSocketHandlers)) {
            $this->loop->addTimer(5.0, function () {
                $this->checkWebSocketHeartbeats();
            }, periodic: true);
        }

        if (!empty($this->webSocketHandlers) && $this->sseHeartbeatInterval > 0) {
            $this->loop->addTimer((float) $this->sseHeartbeatInterval, function () {
                $this->sendSseHeartbeats();
            }, periodic: true);
        }

        if (($this->workerCount > 1 || $this->webSocketBusSingleWorker || $this->sseBusSingleWorker) && !empty($this->webSocketHandlers)) {
            if ($this->webSocketBusType === 'file') {
                $this->loop->addTimer(0.05, function () {
                    $this->pollWebSocketBus();
                }, periodic: true);
            }

            if ($this->sseBusType === 'file') {
                $this->loop->addTimer(0.05, function () {
                    $this->pollSseBus();
                }, periodic: true);
            }

            if ($this->webSocketBusType === 'file' || $this->sseBusType === 'file') {
                $this->loop->addTimer(30.0, function () {
                    if ($this->webSocketBusType === 'file') {
                        $this->compactWebSocketBus();
                    }
                    if ($this->sseBusType === 'file') {
                        $this->compactSseBus();
                    }
                }, periodic: true);
            }
        }

        if (!empty($this->webSocketHandlers) && $this->webSocketBusType === 'redis' && !$this->webSocketRedisBus) {
            $this->webSocketRedisBus = new WebSocketRedisBus(
                (string) ($this->config['websocket_redis_url'] ?? 'redis://127.0.0.1:6379/0'),
                (string) ($this->config['websocket_redis_channel'] ?? 'nexphant:websocket')
            );
        }
        if ($this->webSocketRedisBus) {
            $this->webSocketRedisBus->start($this->loop, function (string $event): void {
                $this->handleWebSocketBusEvent($event);
            });
        }

        if (!empty($this->webSocketHandlers) && $this->sseBusType === 'redis' && !$this->sseRedisBus) {
            $this->sseRedisBus = new WebSocketRedisBus(
                (string) ($this->config['sse_redis_url'] ?? $this->config['websocket_redis_url'] ?? 'redis://127.0.0.1:6379/0'),
                (string) ($this->config['sse_redis_channel'] ?? 'nexphant:sse')
            );
        }
        if ($this->sseRedisBus) {
            $this->sseRedisBus->start($this->loop, function (string $event): void {
                $this->handleSseBusEvent($event);
            });
        }

        if ($profile !== 'benchmark') {
            $this->loop->addTimer(10.0, function () {
                $this->memoryMonitor->sample();
                $this->checkMemoryPressure();
                if ($this->memoryMonitor->detectLeak()) {
                    $this->log("Warning: Memory leak detected - " . $this->memoryMonitor->getReport());
                }
            }, periodic: true);
        }

        if ($statsEnabled) {
            $this->loop->addTimer(1.0, function () {
                $this->publishStats();
            }, periodic: true);
        }

        if ($this->adaptive !== null) {
            $this->loop->addTimer(0.25, function () {
                $this->adaptive->publishToSharedTable();
            }, periodic: true);
        }

        $this->loop->addTimer(60.0, function () {
            foreach ($this->middleware as $middleware) {
                if (is_object($middleware) && method_exists($middleware, 'cleanup')) {
                    $middleware->cleanup();
                }
            }
            $this->objectTracker->cleanupContexts();
        }, periodic: true);

        if ($this->debug) {
            $this->loop->addTimer(30.0, function () {
                $this->logStats();
            }, periodic: true);
        }

        $this->loop->addTimer(1.0, function () {
            $this->checkDrain();
        }, periodic: true);
    }

    private function countPendingWrites(): int
    {
        $count = 0;
        foreach ($this->connections as $conn) {
            if ($conn->hasWriteBuffer()) {
                $count++;
            }
        }
        return $count;
    }

    private function acceptConnections(): void
    {
        if ($this->adaptive !== null) {
            $this->adaptive->stats->activeConnections = count($this->connections);
            $this->adaptive->stats->activeRequests = $this->activeRequests;
            $this->adaptive->stats->pendingWrites = $this->countPendingWrites();
        }

        $limit = ($this->adaptive !== null && $this->adaptive->isAcceptThrottlingEnabled())
            ? $this->adaptive->acceptLimit()
            : $this->maxAcceptPerTick;

        for ($i = 0; $i < $limit; $i++) {
            if (!$this->acceptConnection()) {
                break;
            }
        }
    }

    private function acceptConnection(): bool
    {
        if (!$this->accepting) {
            return false;
        }

        $connCount = count($this->connections);
        $loadRatio = $connCount / max(1, $this->maxConnections);
        if ($loadRatio > 0.9 || $this->activeRequests > $connCount * 2) {
            return false;
        }

        $clientSocket = $this->socketDriver->accept($this->serverSocket);

        if (!$clientSocket) {
            return false;
        }

        if ($this->memoryPressureState === 'hard' || $connCount >= $this->maxConnections) {
            if ($this->memoryPressureState === 'hard') {
                $this->memoryPressureRejected++;
            }
            $response = HttpParser::buildResponse(503, ['Connection' => 'close'], 'Server too busy');
            $this->socketDriver->write($clientSocket, $response);
            $this->socketDriver->close($clientSocket);
            return true;
        }

        $conn = new Connection($clientSocket, ++$this->connectionId, $this->bufferPool);
        $this->objectTracker->track($conn, 'connection', 'server', '', 'active');
        if ($this->memoryPressureState === 'pressure') {
            $conn->setKeepAlive(false);
        }
        $this->connections[$conn->getId()] = $conn;
        $this->totalConnections++;

        $this->loop->addReader($clientSocket, function ($socket) use ($conn) {
            $this->handleRead($conn);
        });

        if ($this->debug) {
            $this->log("Connection #{$conn->getId()} from {$conn->getRemoteAddr()}");
        }

        return true;
    }

    private function handleRead(Connection $conn): void
    {
        $data = $conn->read();

        if ($this->adaptive !== null) {
            $this->adaptive->fairness->recordRead($conn->getId());
        }

        if ($data === null) {
            $this->closeConnection($conn);
            return;
        }

        if ($data === '') {
            return;
        }

        if ($conn->isWebSocket()) {
            $this->handleWebSocketFrames($conn);
            return;
        }

        if ($conn->isSse()) {
            $conn->clearBuffer();
            return;
        }

        // Check request size
        if (strlen($conn->getBuffer()) > $this->maxRequestSize) {
            $this->sendError($conn, 413, 'Request too large');
            return;
        }

        $buffer = $conn->getBuffer();

        $match = $this->fastEngine->match($buffer);
        if ($match !== null) {
            $conn->consumeBuffer($match['consumed']);
            $conn->incrementRequestCount();
            $this->totalRequests++;

            $keepAlive = $match['keep_alive'] && $conn->getRequestCount() < $this->maxRequestsPerConnection;
            $response = $this->fastEngine->getResponse($match['key'], $keepAlive);

            $written = $conn->writeFast($response);
            if ($written < 0) {
                $this->closeConnection($conn);
                return;
            }

            if ($written < strlen($response)) {
                $conn->write(substr($response, $written));
                $this->flushPending($conn, !$keepAlive);
                return;
            }

            if (!$keepAlive) {
                $this->closeConnection($conn);
            }
            return;
        }

        $fastResponse = null;
        if (strncmp($buffer, "GET / ", 6) === 0) {
            $fastResponse = $this->fastPath->get('GET', '/');
        } else {
            $fastParsed = $this->parseRequestLineFast($buffer);
            if ($fastParsed !== null) {
                $fastResponse = $this->fastPath->get($fastParsed[0], $fastParsed[1]);
            }
        }
        if ($fastResponse !== null) {
            $lineEnd = strpos($buffer, "\r\n\r\n");
            if ($lineEnd !== false) {
                $rawLength = $lineEnd + 4;
                $conn->consumeBuffer($rawLength);
                $conn->incrementRequestCount();
                $this->totalRequests++;

                $shouldClose = $conn->getRequestCount() >= $this->maxRequestsPerConnection;
                if ($shouldClose) {
                    $fastResponse = str_replace("Connection: keep-alive", "Connection: close", $fastResponse);
                }

                if ($conn->writeFast($fastResponse) <= 0 || $shouldClose) {
                    $this->closeConnection($conn);
                }
                return;
            }
        }

        $parsed = HttpParser::parseRequest($buffer);

        if ($parsed === null) {
            return; // Incomplete request
        }

        $conn->consumeBuffer($parsed['raw_length']);
        $conn->incrementRequestCount();
        $this->totalRequests++;
        $this->activeRequests++;

        $cacheKey = null;
        if ($this->hotPathCacheEnabled && ($parsed['method'] === 'GET' || $parsed['method'] === 'HEAD')) {
            $cacheKey = ResponseCache::key($parsed['method'], $parsed['uri'], $parsed['headers']);
            $cached = ResponseCache::get($cacheKey);
            if ($cached !== null) {
                $written = $conn->write($cached, $this->maxWriteBufferSize);
                $this->activeRequests = max(0, $this->activeRequests - 1);
                if ($written < 0) {
                    $this->closeConnection($conn);
                }
                if ($conn->hasWriteBuffer()) {
                    $this->flushPending($conn, false);
                }
                return;
            }
        } elseif ($this->hotPathCacheEnabled && $parsed['method'] !== 'OPTIONS' && $parsed['method'] !== 'HEAD') {
            // Only invalidate matching path, not full flush
            ResponseCache::invalidatePath($parsed['path']);
        }

        // Create request/response
        $request = $this->acquireRequest($parsed, $conn);
        if ($cacheKey !== null) {
            $request->setAttribute('__response_cache_key', $cacheKey);
        }
        $requestContext = '';
        if ($this->objectTrackingEnabled && $this->runtimeSafetyEnabled) {
            $requestContext = 'request:' . $this->workerId . ':' . $this->totalRequests;
            $request->setAttribute('__runtime_context', $requestContext);
            $this->objectTracker->openContext($requestContext);
            $this->objectTracker->track($request, 'request', 'http', $requestContext, 'active');
        }
        $response = $this->acquireResponse($requestContext);
        $lifecycleOwner = $request->getAttribute('__lifecycle_owner');
        if ($lifecycleOwner instanceof \Nexphant\Lifecycle\Owner) {
            $lifecycleOwner->own($response);
        }
        if ($cacheKey !== null) {
            $response->cacheAs($cacheKey);
        }

        if (WebSocket::isUpgrade($request)) {
            $this->upgradeWebSocket($request, $response, $conn);
            return;
        }

        // Disable keep-alive under pressure or after max requests
        $keepAlive = $request->wantsKeepAlive() &&
            $conn->getRequestCount() < $this->maxRequestsPerConnection &&
            count($this->connections) < $this->maxConnections * 0.8 &&
            $this->memoryPressureState === 'normal' &&
            !$this->draining;

        if ($conn->getRequestCount() >= $this->maxRequestsPerConnection) {
            $keepAlive = false;
        }

        $conn->setKeepAlive($keepAlive);

        // Fast path: try sync dispatch, fall back to coroutine
        $this->dispatchRequest($request, $response, $conn);
    }

    private function upgradeWebSocket(ServerRequest $request, ServerResponse $response, Connection $conn): void
    {
        $path = $request->path;
        $handler = $this->webSocketHandlers[$path] ?? null;
        if ($handler === null || !WebSocket::handshake($request, $response)) {
            $response->json(['error' => 'Bad WebSocket upgrade'], 400);
            $this->finishHttpRequest($request, $response);
            $this->releaseResponse($response);
            $this->sendError($conn, 400, 'Bad WebSocket upgrade');
            return;
        }

        $conn->markWebSocket($path);
        $conn->setKeepAlive(true);
        $this->totalWebSockets++;
        $this->finishHttpRequest($request, $response);
        $this->sendResponse($conn, $response);

        if ($handler['open']) {
            ($handler['open'])($conn, $this);
        }
    }

    private function handleWebSocketFrames(Connection $conn): void
    {
        if (strlen($conn->getBuffer()) > $this->webSocketMaxReadBufferSize) {
            $this->webSocketReadLimitCloses++;
            $this->closeWebSocket($conn, 1009, 'Read buffer limit');
            return;
        }

        while (true) {
            $payloadLength = WebSocket::peekPayloadLength($conn->getBuffer());
            if ($payloadLength !== null && $payloadLength > $this->webSocketMaxFrameSize) {
                $this->webSocketFrameLimitCloses++;
                $this->closeWebSocket($conn, 1009, 'Frame too large');
                return;
            }

            $frame = WebSocket::decode($conn->getBuffer());
            if (!$frame) {
                return;
            }

            $conn->consumeBuffer($frame['length']);
            $opcode = $frame['opcode'];
            $conn->touch();

            if ($opcode === WebSocket::CLOSE) {
                $this->closeWebSocket($conn);
                return;
            }
            if ($opcode === WebSocket::PING) {
                $this->sendWebSocket($conn, $frame['payload'], WebSocket::PONG);
                continue;
            }
            if ($opcode === WebSocket::PONG) {
                $conn->markPong();
                $this->webSocketPongsReceived++;
                continue;
            }
            if ($opcode !== WebSocket::TEXT && $opcode !== WebSocket::BINARY) {
                continue;
            }

            $handler = $this->webSocketHandlers[$conn->getWebSocketPath()] ?? null;
            if (!$handler) {
                $this->closeWebSocket($conn, 1002, 'No handler');
                return;
            }

            ($handler['message'])($conn, $frame['payload'], $this, $opcode);
        }
    }

    public function sendWebSocket(Connection $conn, string $payload, int $opcode = WebSocket::TEXT, bool $critical = false): bool
    {
        if (!$conn->isWebSocket() || !$conn->isAlive()) {
            return false;
        }

        if (!$critical && $conn->getWriteBufferSize() >= $this->webSocketBackpressureSoftLimit) {
            return $this->handleWebSocketBackpressure($conn);
        }

        $written = $conn->write(WebSocket::encode($payload, $opcode), $this->maxWriteBufferSize);
        if ($written === -2) {
            $this->webSocketBackpressureCloses++;
            $this->closeWebSocket($conn, 1009, 'Write buffer limit');
            return false;
        }

        if ($written < 0) {
            $this->closeConnection($conn);
            return false;
        }

        if ($conn->hasWriteBuffer()) {
            $this->flushPending($conn, false);
        }

        return true;
    }

    private function handleWebSocketBackpressure(Connection $conn): bool
    {
        if ($this->webSocketBackpressurePolicy === 'skip') {
            $this->webSocketBackpressureSkips++;
            return false;
        }

        $this->webSocketBackpressureCloses++;
        $this->closeWebSocket($conn, 1009, 'Backpressure');
        return false;
    }

    private function sendWebSocketPing(Connection $conn): void
    {
        if (!$conn->isWebSocket() || !$conn->isAlive() || $conn->isClosing()) {
            return;
        }

        $conn->markPing();
        $this->webSocketPingsSent++;
        $this->sendWebSocket($conn, (string) microtime(true), WebSocket::PING, true);
    }

    public function broadcastWebSocket(string $path, string $payload, ?Connection $except = null, int $opcode = WebSocket::TEXT, bool $publish = true): int
    {
        $targetIds = $this->collectWebSocketTargets($path, null, $except);

        if ($targetIds !== []) {
            $this->webSocketBroadcasts++;
            $this->queueWebSocketBroadcast($targetIds, $payload, $opcode);
        }

        if ($publish && $this->shouldPublishWebSocketBus()) {
            $this->publishWebSocketBus($path, $payload, $opcode, null);
        }

        return count($targetIds);
    }

    public function joinWebSocketRoom(Connection $conn, string $room): void
    {
        if (!$conn->isWebSocket()) {
            return;
        }

        $path = $conn->getWebSocketPath();
        $room = $this->normalizeWebSocketRoom($room);
        $id = $conn->getId();
        $this->webSocketRooms[$path][$room][$id] = true;
        $this->webSocketConnectionRooms[$id][$path][$room] = true;
    }

    public function leaveWebSocketRoom(Connection $conn, string $room): void
    {
        if (!$conn->isWebSocket()) {
            return;
        }

        $path = $conn->getWebSocketPath();
        $room = $this->normalizeWebSocketRoom($room);
        $id = $conn->getId();
        unset($this->webSocketRooms[$path][$room][$id], $this->webSocketConnectionRooms[$id][$path][$room]);
        if (empty($this->webSocketRooms[$path][$room])) {
            unset($this->webSocketRooms[$path][$room]);
        }
        if (empty($this->webSocketRooms[$path])) {
            unset($this->webSocketRooms[$path]);
        }
        if (empty($this->webSocketConnectionRooms[$id][$path])) {
            unset($this->webSocketConnectionRooms[$id][$path]);
        }
        if (empty($this->webSocketConnectionRooms[$id])) {
            unset($this->webSocketConnectionRooms[$id]);
        }
    }

    public function broadcastWebSocketRoom(string $path, string $room, string $payload, ?Connection $except = null, int $opcode = WebSocket::TEXT, bool $publish = true): int
    {
        $room = $this->normalizeWebSocketRoom($room);
        $targetIds = $this->collectWebSocketTargets($path, $room, $except);

        if ($targetIds !== []) {
            $this->webSocketBroadcasts++;
            $this->queueWebSocketBroadcast($targetIds, $payload, $opcode);
        }

        if ($publish && $this->shouldPublishWebSocketBus()) {
            $this->publishWebSocketBus($path, $payload, $opcode, $room);
        }

        return count($targetIds);
    }

    private function collectWebSocketTargets(string $path, ?string $room, ?Connection $except): array
    {
        $targetIds = [];
        $source = $room === null ? $this->connections : array_intersect_key($this->connections, $this->webSocketRooms[$path][$room] ?? []);
        foreach ($source as $id => $conn) {
            if (!$conn->isWebSocket() || $conn->getWebSocketPath() !== $path) {
                continue;
            }
            if ($except && $conn->getId() === $except->getId()) {
                continue;
            }
            $targetIds[] = $id;
        }
        return $targetIds;
    }

    private function queueWebSocketBroadcast(array $targetIds, string $payload, int $opcode): void
    {
        $total = count($targetIds);
        if ($total === 0) {
            return;
        }

        $offset = 0;
        $batchSize = $this->webSocketBroadcastBatchSize;
        $this->webSocketPendingBroadcastBatches += (int) ceil($total / $batchSize);

        $sendChunk = null;
        $sendChunk = function () use (&$sendChunk, &$offset, $targetIds, $total, $batchSize, $payload, $opcode): void {
            $limit = min($total, $offset + $batchSize);
            for (; $offset < $limit; $offset++) {
                $conn = $this->connections[$targetIds[$offset]] ?? null;
                if (!$conn || !$conn->isWebSocket() || !$conn->isAlive()) {
                    continue;
                }

                if ($this->sendWebSocket($conn, $payload, $opcode)) {
                    $this->webSocketBroadcastDeliveries++;
                }
            }

            $this->webSocketBroadcastBatches++;
            $this->webSocketPendingBroadcastBatches = max(0, $this->webSocketPendingBroadcastBatches - 1);

            if ($offset < $total) {
                $this->loop->defer($sendChunk);
            }
        };

        $this->loop->defer($sendChunk);
    }

    private function publishWebSocketBus(string $path, string $payload, int $opcode, ?string $room): void
    {
        $event = json_encode([
            'id' => bin2hex(random_bytes(8)),
            'pid' => getmypid(),
            'worker_id' => $this->workerId,
            'time' => microtime(true),
            'path' => $path,
            'room' => $room,
            'opcode' => $opcode,
            'payload' => base64_encode($payload),
        ], JSON_UNESCAPED_SLASHES);

        if ($event === false) {
            return;
        }

        if ($this->webSocketRedisBus) {
            $this->webSocketRedisBus->publish($event);
            return;
        }

        if ($this->webSocketBusType !== 'file') {
            return;
        }

        $this->prepareStatsDir();
        @file_put_contents($this->webSocketBusFile, $event . "\n", FILE_APPEND | LOCK_EX);
    }

    private function shouldPublishWebSocketBus(): bool
    {
        return $this->webSocketBusType === 'redis' || ($this->webSocketBusType === 'file' && ($this->workerCount > 1 || $this->webSocketBusSingleWorker));
    }

    private function pollWebSocketBus(): void
    {
        if (!is_file($this->webSocketBusFile)) {
            return;
        }

        $size = (int) filesize($this->webSocketBusFile);
        if ($this->webSocketBusOffset > $size) {
            $this->webSocketBusOffset = 0;
        }
        if ($this->webSocketBusOffset === $size) {
            return;
        }

        $fp = @fopen($this->webSocketBusFile, 'rb');
        if (!$fp) {
            return;
        }

        @fseek($fp, $this->webSocketBusOffset);
        $chunk = stream_get_contents($fp);
        $this->webSocketBusOffset = (int) ftell($fp);
        @fclose($fp);

        if ($chunk === false || $chunk === '') {
            return;
        }

        foreach (explode("\n", trim($chunk)) as $line) {
            if ($line === '') {
                continue;
            }

            $this->handleWebSocketBusEvent($line);
        }
    }

    private function handleWebSocketBusEvent(string $line): void
    {
        $event = json_decode($line, true);
        if (!is_array($event) || (int) ($event['pid'] ?? 0) === getmypid()) {
            return;
        }
        $eventId = (string) ($event['id'] ?? '');
        if ($eventId !== '' && isset($this->webSocketSeenEvents[$eventId])) {
            return;
        }
        if ($eventId !== '') {
            $this->rememberWebSocketEvent($eventId);
        }

        $payload = base64_decode((string) ($event['payload'] ?? ''), true);
        if ($payload === false) {
            return;
        }

        $path = (string) ($event['path'] ?? '');
        $opcode = (int) ($event['opcode'] ?? WebSocket::TEXT);
        $room = isset($event['room']) && $event['room'] !== null ? (string) $event['room'] : null;
        if ($room !== null) {
            $this->broadcastWebSocketRoom($path, $room, $payload, null, $opcode, false);
        } else {
            $this->broadcastWebSocket($path, $payload, null, $opcode, false);
        }
    }

    private function normalizeWebSocketRoom(string $room): string
    {
        $room = trim($room);
        if ($room === '') {
            return 'global';
        }

        $room = preg_replace('/[^A-Za-z0-9:._-]/', '-', $room) ?? 'global';
        return substr($room, 0, 96) ?: 'global';
    }

    private function rememberWebSocketEvent(string $eventId): void
    {
        $this->webSocketSeenEvents[$eventId] = true;
        if (count($this->webSocketSeenEvents) > 4096) {
            $this->webSocketSeenEvents = array_slice($this->webSocketSeenEvents, -2048, null, true);
        }
    }

    private function compactWebSocketBus(): void
    {
        if ($this->workerId !== 1 || !is_file($this->webSocketBusFile)) {
            return;
        }

        $size = (int) filesize($this->webSocketBusFile);
        if ($size <= $this->webSocketBusMaxBytes) {
            return;
        }

        $keepBytes = max(65536, (int) ($this->webSocketBusMaxBytes / 4));
        $fp = @fopen($this->webSocketBusFile, 'rb');
        if (!$fp) {
            return;
        }

        @fseek($fp, max(0, $size - $keepBytes));
        $tail = stream_get_contents($fp);
        @fclose($fp);

        if ($tail === false) {
            return;
        }

        $newline = strpos($tail, "\n");
        if ($newline !== false) {
            $tail = substr($tail, $newline + 1);
        }

        $tmp = $this->webSocketBusFile . '.tmp';
        if (@file_put_contents($tmp, $tail, LOCK_EX) !== false) {
            @rename($tmp, $this->webSocketBusFile);
            $this->webSocketBusOffset = min($this->webSocketBusOffset, strlen($tail));
        }
    }

    public function startSse(ServerRequest $request, ServerResponse $response, Connection $conn): void
    {
        $request->setAttribute('__stream_started', true);
        if (!$this->authorizeSse($request)) {
            $this->sseAuthFailures++;
            $response->json(['error' => 'Unauthorized'], 401);
            $this->finishHttpRequest($request, $response);
            $this->sendResponse($conn, $response);
            return;
        }

        if (!$conn->isAlive()) {
            $this->finishHttpRequest($request, $response);
            $this->releaseResponse($response);
            return;
        }

        $channel = $this->normalizeSseChannel((string) $request->query('channel', 'global'));
        $lastEventId = $this->sseLastEventId($request);
        $path = $request->path;
        $conn->markSse($path, $channel);
        $conn->setKeepAlive(true);
        $this->totalSseConnections++;

        $response
            ->header('Content-Type', 'text/event-stream; charset=utf-8')
            ->header('Cache-Control', 'no-cache')
            ->header('Connection', 'keep-alive')
            ->header('X-Accel-Buffering', 'no')
            ->body(": connected\n\n");

        $this->finishHttpRequest($request, $response);
        $this->sendResponse($conn, $response);
        if ($lastEventId !== '') {
            $this->replaySse($conn, $path, $channel, $lastEventId);
        }
    }

    public function closeSse(Connection $conn): void
    {
        if ($conn->isSse()) {
            $this->closeConnection($conn);
        }
    }

    public function sendSse(Connection $conn, mixed $data, ?string $event = null, ?string $id = null, ?int $retry = null): bool
    {
        if (!$conn->isSse() || !$conn->isAlive()) {
            return false;
        }

        $id ??= $this->nextSseEventId();
        $written = $conn->write($this->formatSse($data, $event, $id, $retry), $this->maxWriteBufferSize);
        if ($written === -2) {
            $this->sseBackpressureCloses++;
            $this->closeConnection($conn);
            return false;
        }
        if ($written < 0) {
            $this->closeConnection($conn);
            return false;
        }
        if ($conn->hasWriteBuffer()) {
            $this->flushPending($conn, false);
        }

        $this->sseEventsSent++;
        return true;
    }

    public function broadcastSse(string $path, mixed $data, ?string $event = 'message', bool $publish = true, string $channel = 'global'): int
    {
        $channel = $this->normalizeSseChannel($channel);
        $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return 0;
        }

        $id = $this->nextSseEventId();
        $this->lastSseEventId = $id;
        return $this->broadcastSseWithId($path, $payload, $event, $publish, $channel, $id);
    }

    public function getLastSseEventId(): string
    {
        return $this->lastSseEventId;
    }

    private function broadcastSseWithId(string $path, string $payload, ?string $event, bool $publish, string $channel, string $id): int
    {
        $sent = 0;
        $this->sseBroadcasts++;
        $this->rememberSseReplay($path, $channel, $payload, $event, $id);
        foreach ($this->connections as $conn) {
            if ($conn->isSse() && $conn->getSsePath() === $path && $conn->getSseChannel() === $channel && $this->sendSse($conn, $payload, $event, $id)) {
                $sent++;
            }
        }

        $this->sseLocalDeliveries += $sent;
        if ($publish && $this->shouldPublishSseBus()) {
            $this->publishSseBus($path, $payload, $event, $channel, $id);
        }

        return $sent;
    }

    private function publishSseBus(string $path, string $payload, ?string $event, string $channel, string $id): void
    {
        $line = json_encode([
            'bus_id' => bin2hex(random_bytes(8)),
            'id' => $id,
            'pid' => getmypid(),
            'worker_id' => $this->workerId,
            'time' => microtime(true),
            'path' => $path,
            'channel' => $channel,
            'event' => $event,
            'payload' => base64_encode($payload),
        ], JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return;
        }

        if ($this->sseRedisBus) {
            $this->sseRedisBus->publish($line);
            $this->sseBusPublished++;
            return;
        }

        if ($this->sseBusType !== 'file') {
            return;
        }

        $this->prepareStatsDir();
        if (@file_put_contents($this->sseBusFile, $line . "\n", FILE_APPEND | LOCK_EX) !== false) {
            $this->sseBusPublished++;
        }
    }

    private function shouldPublishSseBus(): bool
    {
        return $this->sseBusType === 'redis' || ($this->sseBusType === 'file' && ($this->workerCount > 1 || $this->sseBusSingleWorker));
    }

    private function pollSseBus(): void
    {
        if (!is_file($this->sseBusFile)) {
            return;
        }

        $size = (int) filesize($this->sseBusFile);
        if ($this->sseBusOffset > $size) {
            $this->sseBusOffset = 0;
        }
        if ($this->sseBusOffset === $size) {
            return;
        }

        $fp = @fopen($this->sseBusFile, 'rb');
        if (!$fp) {
            return;
        }

        @fseek($fp, $this->sseBusOffset);
        $chunk = stream_get_contents($fp);
        $this->sseBusOffset = (int) ftell($fp);
        @fclose($fp);

        if ($chunk === false || $chunk === '') {
            return;
        }

        foreach (explode("\n", trim($chunk)) as $line) {
            if ($line !== '') {
                $this->handleSseBusEvent($line);
            }
        }
    }

    private function handleSseBusEvent(string $line): void
    {
        $event = json_decode($line, true);
        if (!is_array($event) || (int) ($event['pid'] ?? 0) === getmypid()) {
            return;
        }

        $eventId = (string) ($event['bus_id'] ?? $event['id'] ?? '');
        if ($eventId !== '' && isset($this->sseSeenEvents[$eventId])) {
            return;
        }
        if ($eventId !== '') {
            $this->rememberSseEvent($eventId);
        }

        $payload = base64_decode((string) ($event['payload'] ?? ''), true);
        if ($payload === false) {
            return;
        }

        $before = $this->sseEventsSent;
        $this->broadcastSseWithId(
            (string) ($event['path'] ?? ''),
            $payload,
            $event['event'] ?? 'message',
            false,
            $this->normalizeSseChannel((string) ($event['channel'] ?? 'global')),
            (string) ($event['id'] ?? $this->nextSseEventId())
        );
        $this->sseBusDeliveries += max(0, $this->sseEventsSent - $before);
    }

    private function authorizeSse(ServerRequest $request): bool
    {
        if ($this->sseAuthToken === '') {
            return true;
        }

        $token = (string) $request->query('token', '');
        if ($token === '') {
            $authorization = trim((string) $request->header('authorization', ''));
            if (stripos($authorization, 'Bearer ') === 0) {
                $token = trim(substr($authorization, 7));
            }
        }

        return hash_equals($this->sseAuthToken, $token);
    }

    private function sseLastEventId(ServerRequest $request): string
    {
        $id = trim((string) $request->header('last-event-id', ''));
        if ($id !== '') {
            return substr($id, 0, 128);
        }

        $id = trim((string) ($request->query('lastEventId', $request->query('last_event_id', ''))));
        return $id === '' ? '' : substr($id, 0, 128);
    }

    private function replaySse(Connection $conn, string $path, string $channel, string $lastEventId): void
    {
        $this->sseReplayRequests++;
        $events = $this->sseReplayBuffer[$path][$channel] ?? [];
        $found = false;
        $sent = 0;
        foreach ($events as $item) {
            if (!$found) {
                if (($item['id'] ?? '') === $lastEventId) {
                    $found = true;
                }
                continue;
            }

            if ($this->sendSse($conn, (string) $item['payload'], $item['event'] ?? 'message', (string) $item['id'])) {
                $sent++;
            }
        }

        if (!$found) {
            $this->sseReplayExpired++;
        }
        $this->sseReplayedEvents += $sent;
    }

    private function rememberSseReplay(string $path, string $channel, string $payload, ?string $event, string $id): void
    {
        if ($this->sseReplayLimit <= 0) {
            return;
        }

        $this->sseReplayBuffer[$path][$channel][] = [
            'id' => $id,
            'event' => $event,
            'payload' => $payload,
        ];

        $count = count($this->sseReplayBuffer[$path][$channel]);
        if ($count > $this->sseReplayLimit) {
            $this->sseReplayBuffer[$path][$channel] = array_slice($this->sseReplayBuffer[$path][$channel], -$this->sseReplayLimit);
        }
    }

    private function nextSseEventId(): string
    {
        $this->sseEventSequence++;
        return sprintf('%d-%d-%d', (int) floor(microtime(true) * 1000), $this->workerId, $this->sseEventSequence);
    }

    private function rememberSseEvent(string $eventId): void
    {
        $this->sseSeenEvents[$eventId] = true;
        if (count($this->sseSeenEvents) > 4096) {
            $this->sseSeenEvents = array_slice($this->sseSeenEvents, -2048, null, true);
        }
    }

    private function compactSseBus(): void
    {
        if ($this->workerId !== 1 || !is_file($this->sseBusFile)) {
            return;
        }

        $size = (int) filesize($this->sseBusFile);
        if ($size <= $this->sseBusMaxBytes) {
            return;
        }

        $keepBytes = max(65536, (int) ($this->sseBusMaxBytes / 4));
        $fp = @fopen($this->sseBusFile, 'rb');
        if (!$fp) {
            return;
        }

        @fseek($fp, max(0, $size - $keepBytes));
        $tail = stream_get_contents($fp);
        @fclose($fp);

        if ($tail === false) {
            return;
        }

        $newline = strpos($tail, "\n");
        if ($newline !== false) {
            $tail = substr($tail, $newline + 1);
        }

        $tmp = $this->sseBusFile . '.tmp';
        if (@file_put_contents($tmp, $tail, LOCK_EX) !== false) {
            @rename($tmp, $this->sseBusFile);
            $this->sseBusOffset = min($this->sseBusOffset, strlen($tail));
        }
    }

    private function normalizeSseChannel(string $channel): string
    {
        $channel = trim($channel);
        if ($channel === '') {
            return 'global';
        }

        $channel = preg_replace('/[^A-Za-z0-9:._-]/', '-', $channel) ?? 'global';
        return substr($channel, 0, 96) ?: 'global';
    }

    private function sendSseHeartbeats(): void
    {
        foreach ($this->connections as $conn) {
            if (!$conn->isSse() || !$conn->isAlive()) {
                continue;
            }

            $written = $conn->write(": ping\n\n", $this->maxWriteBufferSize);
            if ($written === -2) {
                $this->sseBackpressureCloses++;
                $this->closeConnection($conn);
                continue;
            }
            if ($written < 0) {
                $this->closeConnection($conn);
                continue;
            }
            if ($conn->hasWriteBuffer()) {
                $this->flushPending($conn, false);
            }
            $this->sseHeartbeatsSent++;
        }
    }

    private function formatSse(mixed $data, ?string $event, ?string $id, ?int $retry): string
    {
        if (!is_string($data)) {
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $data = $encoded === false ? '' : $encoded;
        }

        $payload = '';
        if ($id !== null && $id !== '') {
            $payload .= 'id: ' . str_replace(["\r", "\n"], '', $id) . "\n";
        }
        if ($event !== null && $event !== '') {
            $payload .= 'event: ' . str_replace(["\r", "\n"], '', $event) . "\n";
        }
        if ($retry !== null) {
            $payload .= 'retry: ' . max(0, $retry) . "\n";
        }

        foreach (preg_split('/\r\n|\r|\n/', $data) ?: [''] as $line) {
            $payload .= 'data: ' . $line . "\n";
        }
        return $payload . "\n";
    }

    public function closeWebSocket(Connection $conn, int $code = 1000, string $reason = ''): void
    {
        if (!$conn->isWebSocket() || $conn->isClosing()) {
            $this->closeConnection($conn);
            return;
        }

        $conn->markClosing();
        if ($conn->isAlive()) {
            $conn->write(WebSocket::encode(pack('n', $code) . $reason, WebSocket::CLOSE), $this->maxWriteBufferSize);
            if ($conn->hasWriteBuffer()) {
                $this->flushPending($conn, true);
                return;
            }
        }
        $this->closeConnection($conn);
    }

    private function dispatchRequest(ServerRequest $request, ServerResponse $response, Connection $conn): void
    {
        try {
            if (!$this->runtimeSafetyEnabled && empty($this->middleware)) {
                if ($this->requestHandler) {
                    $result = ($this->requestHandler)($request, $response);
                    if (!($result instanceof \Generator) && ($response->isSent() || $request->getAttribute('__stream_started', false))) {
                        return;
                    }
                    if ($result instanceof \Generator) {
                        Coroutine::create($this->resumeHandler($result, $request, $response, $conn));
                        return;
                    }
                } else {
                    $response->notFound();
                }
                $this->finishHttpRequest($request, $response);
                $this->sendResponse($conn, $response);
                return;
            }

            if (!empty($this->middleware)) {
                foreach ($this->middleware as $middleware) {
                    $result = $middleware($request, $response);
                    if ($result instanceof \Generator) {
                        Coroutine::create($this->handleRequestAsync($request, $response, $conn, $result));
                        return;
                    }
                    if ($result === false || $response->isSent()) {
                        $this->finishHttpRequest($request, $response);
                        $this->sendResponse($conn, $response);
                        return;
                    }
                }
            }

            if ($this->requestHandler) {
                $result = ($this->requestHandler)($request, $response);
                if ($result instanceof \Generator) {
                    Coroutine::create($this->resumeHandler($result, $request, $response, $conn));
                    return;
                }
                if ($response->isSent() || $request->getAttribute('__stream_started', false)) {
                    return;
                }
            } else {
                $response->notFound();
            }
        } catch (\Throwable $e) {
            $lifecycleOwner = $request->getAttribute('__lifecycle_owner');
            if ($lifecycleOwner instanceof \Nexphant\Lifecycle\Owner) {
                $lifecycleOwner->cancel();
            }
            $this->handleError($e, $response);
        }

        $this->finishHttpRequest($request, $response);
        $this->sendResponse($conn, $response);
    }

    private function handleRequestAsync(ServerRequest $request, ServerResponse $response, Connection $conn, \Generator $pendingMiddleware): \Generator
    {
        try {
            yield from $pendingMiddleware;
            if ($response->isSent()) {
                $this->finishHttpRequest($request, $response);
                $this->sendResponse($conn, $response);
                return;
            }

            // Continue remaining middleware
            $found = false;
            foreach ($this->middleware as $middleware) {
                if (!$found) {
                    $found = true;
                    continue;
                }
                $result = $middleware($request, $response);
                if ($result instanceof \Generator) {
                    yield from $result;
                }
                if ($result === false || $response->isSent()) {
                    $this->finishHttpRequest($request, $response);
                    $this->sendResponse($conn, $response);
                    return;
                }
            }

            if ($this->requestHandler) {
                $result = ($this->requestHandler)($request, $response);
                if ($result instanceof \Generator) {
                    yield from $result;
                }
                if ($response->isSent() || $request->getAttribute('__stream_started', false)) {
                    return;
                }
            } else {
                $response->notFound();
            }

            $this->finishHttpRequest($request, $response);
            $this->sendResponse($conn, $response);
        } catch (\Throwable $e) {
            $lifecycleOwner = $request->getAttribute('__lifecycle_owner');
            if ($lifecycleOwner instanceof \Nexphant\Lifecycle\Owner) {
                $lifecycleOwner->cancel();
            }
            throw $e;
        }
    }

    private function resumeHandler(\Generator $gen, ServerRequest $request, ServerResponse $response, Connection $conn): \Generator
    {
        try {
            yield from $gen;
            if ($response->isSent() || $request->getAttribute('__stream_started', false)) {
                return;
            }
        } catch (\Throwable $e) {
            $lifecycleOwner = $request->getAttribute('__lifecycle_owner');
            if ($lifecycleOwner instanceof \Nexphant\Lifecycle\Owner) {
                $lifecycleOwner->cancel();
            }
            $this->handleError($e, $response);
        }
        $this->finishHttpRequest($request, $response);
        $this->sendResponse($conn, $response);
    }

    private function handleError(\Throwable $e, ServerResponse $response): void
    {
        $this->log("Error: " . $e->getMessage());
        if ($this->debug) {
            $response->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        } else {
            $response->error();
        }
    }

    private function finishHttpRequest(ServerRequest $request, ServerResponse $response): void
    {
        if ($this->runtimeSafetyEnabled) {
            $durationMs = max(0.0, (microtime(true) - $request->time) * 1000);
            $this->recordHttpMetrics($request->method, $request->path, $response->getStatus(), $durationMs);
        } else {
            // Minimal tracking in performance mode
            $this->httpStatusCounts[(string) $response->getStatus()] = ($this->httpStatusCounts[(string) $response->getStatus()] ?? 0) + 1;
            $this->httpLatencyCount++;
        }

        // Close lifecycle owner first (cancel + close children + release resources)
        $lifecycleOwner = $request->getAttribute('__lifecycle_owner');
        if ($lifecycleOwner instanceof \Nexphant\Lifecycle\Owner) {
            $lifecycleOwner->cancel();
            $lifecycleOwner->close();
        }

        // Close request owner
        if (class_exists('\Nexphant\Runtime\Runtime') && \Nexphant\Runtime\Runtime::available()) {
            $ownerId = $request->getAttribute('__owner_id');
            if ($ownerId) {
                // Finish in-flight tracking
                if (class_exists('\Nexphant\Core\Drain\DrainController')) {
                    \Nexphant\Core\Drain\DrainController::instance()->finishInFlight($ownerId);
                }
                \Nexphant\Runtime\Runtime::owners()->close($ownerId, 'request_completed');
            }
        }

        if ($this->objectTrackingEnabled) {
            $context = (string) $request->getAttribute('__runtime_context', '');
            $this->objectTracker->release($request);
            if ($context !== '') {
                $this->objectTracker->closeContext($context);
            }
        }
        $this->releaseRequest($request);
        $this->activeRequests = max(0, $this->activeRequests - 1);
    }

    private function recordHttpMetrics(string $method, string $path, int $status, float $durationMs): void
    {
        // Sampling: skip expensive work if not sampled
        if ($this->metricsSampleRate > 1) {
            $this->metricsSampleCounter++;
            if ($this->metricsSampleCounter % $this->metricsSampleRate !== 0) {
                // Still record basic counters
                $this->httpStatusCounts[(string) $status] = ($this->httpStatusCounts[(string) $status] ?? 0) + 1;
                $this->httpLatencyCount++;
                $this->httpLatencySumMs += $durationMs;
                return;
            }
        }

        $statusKey = (string) $status;
        $this->httpStatusCounts[$statusKey] = ($this->httpStatusCounts[$statusKey] ?? 0) + 1;
        $this->httpLatencyCount++;
        $this->httpLatencySumMs += $durationMs;
        $this->httpLatencyMaxMs = max($this->httpLatencyMaxMs, $durationMs);

        // Route counting + normalization (expensive)
        if ($this->routeLatencyEnabled || $this->runtimeSafetyEnabled) {
            $routeKey = $method . ' ' . $this->normalizeMetricPath($path);
            $this->httpRouteCounts[$routeKey] = ($this->httpRouteCounts[$routeKey] ?? 0) + 1;

            if ($this->routeLatencyEnabled && $this->httpRouteLatencySampleLimit > 0) {
                $sample = $this->httpRouteLatencySamples[$routeKey] ?? ['values' => [], 'pos' => 0, 'count' => 0];
                if ($sample['count'] < $this->httpRouteLatencySampleLimit) {
                    $sample['values'][] = $durationMs;
                    $sample['count']++;
                } else {
                    $sample['values'][$sample['pos']] = $durationMs;
                    $sample['pos'] = ($sample['pos'] + 1) % $this->httpRouteLatencySampleLimit;
                }
                $this->httpRouteLatencySamples[$routeKey] = $sample;
            }
        }

        // Histogram buckets
        if ($this->histogramEnabled) {
            $lo = 0;
            $hi = 11;
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if (self::LATENCY_BUCKETS_MS[$mid] < $durationMs) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            for ($i = $lo; $i < 12; $i++) {
                $this->httpLatencyBuckets[(string) self::LATENCY_BUCKETS_MS[$i]]++;
            }
            $this->httpLatencyBuckets['+Inf']++;
        }
    }

    private function sendResponse(Connection $conn, ServerResponse $response): void
    {
        if (!$conn->isAlive()) {
            $this->releaseResponse($response);
            $this->closeConnection($conn);
            return;
        }

        $data = $response->build($conn->isKeepAlive());
        $status = $response->getStatus();
        if ($status === 200 && $response->getCacheKey() !== null) {
            ResponseCache::set($response->getCacheKey(), $data);
        }
        $written = $conn->write($data, $this->maxWriteBufferSize);
        $this->releaseResponse($response);

        if ($this->debug) {
            $this->log("Response #{$conn->getId()}: {$status}");
        }

        if ($written === -2) {
            $this->closeConnection($conn);
            return;
        }

        if ($written < 0) {
            $this->closeConnection($conn);
            return;
        }

        if ($conn->hasWriteBuffer()) {
            $this->flushPending($conn, !$conn->isKeepAlive());
            return;
        }

        if (!$conn->isKeepAlive()) {
            $this->closeConnection($conn);
        }
    }

    private function sendError(Connection $conn, int $status, string $message): void
    {
        $response = $this->acquireResponse();
        $response->json(['error' => $message], $status);
        $data = $response->build(false);
        $written = $conn->write($data, $this->maxWriteBufferSize);
        $this->releaseResponse($response);
        if ($written < 0) {
            $this->closeConnection($conn);
            return;
        }
        if ($conn->hasWriteBuffer()) {
            $this->flushPending($conn, true);
            return;
        }

        $this->closeConnection($conn);
    }

    private function acquireResponse(string $context = ''): ServerResponse
    {
        /** @var ServerResponse $response */
        $response = $this->responsePool->acquire('http', $context);
        return $response;
    }

    private function acquireRequest(array $parsed, Connection $conn): ServerRequest
    {
        /** @var ServerRequest $request */
        $request = $this->requestPool->acquire('http', 'conn:' . $conn->getId());
        $request->hydrate($parsed, $conn);
        if ($this->runtimeSafetyEnabled && class_exists('\Nexphant\Lifecycle\Lifecycle')) {
            $lifecycleOwner = \Nexphant\Lifecycle\Lifecycle::request();
            $lifecycleOwner->own($request);
            $request->setAttribute('__lifecycle_owner', $lifecycleOwner);
        }

        // Track request with owner type 'request'
        if (class_exists('\Nexphant\Runtime\Runtime') && \Nexphant\Runtime\Runtime::available()) {
            $owner = \Nexphant\Runtime\Runtime::owners()->open(
                \Nexphant\Core\Ownership\OwnerType::REQUEST,
                null,
                ['method' => $parsed['method'] ?? 'GET', 'path' => $parsed['path'] ?? '/']
            );
            $request->setAttribute('__owner_id', $owner->id()->toString());

            // Track in-flight
            if (class_exists('\Nexphant\Core\Drain\DrainController')) {
                \Nexphant\Core\Drain\DrainController::instance()->trackInFlight($owner->id());
            }

            // Set context for request
            $traceId = bin2hex(random_bytes(16));
            \Nexphant\Runtime\Runtime::withContext([
                'trace_id' => $traceId,
                'request_id' => $request->getAttribute('__id', ''),
                'owner_id' => $owner->id()->toString(),
                'owner_type' => 'request',
            ], function () {});
        }

        return $request;
    }

    private function releaseRequest(ServerRequest $request): void
    {
        $this->requestPool->release($request);
    }

    private function releaseResponse(ServerResponse $response): void
    {
        $this->responsePool->release($response);
    }

    private function flushPending(Connection $conn, bool $closeWhenDone): void
    {
        $socket = $conn->getSocket();
        if (!$socket || !\Nexphant\Server\Socket\SocketDriverFactory::isValidSocket($socket)) {
            return;
        }

        $this->loop->addWriter($socket, function ($socket) use ($conn, $closeWhenDone) {
            if ($conn->flush() < 0) {
                $this->closeConnection($conn);
                return;
            }

            if ($this->adaptive !== null) {
                $this->adaptive->fairness->recordWrite($conn->getId());
            }

            if ($conn->hasWriteBuffer()) {
                return;
            }

            $this->loop->removeWriter($socket);
            if ($closeWhenDone) {
                $this->closeConnection($conn);
            }
        });
    }

    private function closeConnection(Connection $conn): void
    {
        $id = $conn->getId();
        $socket = $conn->getSocket();
        $wasWebSocket = $conn->isWebSocket();
        $webSocketPath = $conn->getWebSocketPath();

        if ($socket && \Nexphant\Server\Socket\SocketDriverFactory::isValidSocket($socket)) {
            $this->loop->removeReader($socket);
            $this->loop->removeWriter($socket);
        }

        $conn->close();
        unset($this->connections[$id]);
        $this->objectTracker->release($conn, 'closed');

        if ($wasWebSocket) {
            $handler = $this->webSocketHandlers[$webSocketPath] ?? null;
            if ($handler && $handler['close']) {
                ($handler['close'])($conn, $this);
            }
            $this->leaveAllWebSocketRooms($conn);
        }

        if ($this->debug) {
            $this->log("Connection #{$id} closed");
        }
    }

    private function leaveAllWebSocketRooms(Connection $conn): void
    {
        $id = $conn->getId();
        foreach ($this->webSocketConnectionRooms[$id] ?? [] as $path => $rooms) {
            foreach ($rooms as $room => $_) {
                unset($this->webSocketRooms[$path][$room][$id]);
                if (empty($this->webSocketRooms[$path][$room])) {
                    unset($this->webSocketRooms[$path][$room]);
                }
            }
            if (empty($this->webSocketRooms[$path])) {
                unset($this->webSocketRooms[$path]);
            }
        }
        unset($this->webSocketConnectionRooms[$id]);
    }

    private function cleanupConnections(): void
    {
        $now = microtime(true);
        $timeout = $this->keepAliveTimeout;
        $cleaned = 0;

        foreach ($this->connections as $conn) {
            if (!$conn->isAlive()) {
                $this->closeConnection($conn);
                $cleaned++;
                continue;
            }

            $connTimeout = $conn->isWebSocket() ? $this->webSocketTimeout : ($conn->isSse() ? $this->sseTimeout : $timeout);
            if ($now - $conn->getLastActivity() > $connTimeout) {
                if ($conn->isWebSocket()) {
                    $this->webSocketIdleCloses++;
                    $this->closeWebSocket($conn, 1001, 'Idle timeout');
                    $cleaned++;
                    continue;
                }
                $this->closeConnection($conn);
                $cleaned++;
            }
        }

        if ($cleaned > 50) {
            gc_collect_cycles();
        }
    }

    private function checkMemoryPressure(): void
    {
        $usage = memory_get_usage(true);
        $ratio = $this->memoryLimit > 0 ? $usage / $this->memoryLimit : 0.0;
        $state = 'normal';
        if ($ratio >= $this->memoryHardPressureThreshold) {
            $state = 'hard';
        } elseif ($ratio >= $this->memoryPressureThreshold) {
            $state = 'pressure';
        }

        if ($state !== $this->memoryPressureState) {
            $this->memoryPressureEvents++;
            $this->memoryPressureState = $state;
        }

        if ($state === 'normal') {
            $this->resumeAccepting();
            return;
        }

        gc_collect_cycles();
        if ($state === 'hard') {
            $this->pauseAccepting();
            $this->closeIdlePressureConnections();
        }
    }

    private function closeIdlePressureConnections(): void
    {
        $now = microtime(true);
        $closed = 0;
        foreach ($this->connections as $conn) {
            if ($conn->isWebSocket()) {
                if ($now - $conn->getLastActivity() > min(30, $this->webSocketTimeout)) {
                    $this->closeWebSocket($conn, 1001, 'Memory pressure');
                    $closed++;
                }
                continue;
            }

            if ($conn->isSse()) {
                if ($now - $conn->getLastActivity() > min(30, $this->sseTimeout)) {
                    $this->closeConnection($conn);
                    $closed++;
                }
                continue;
            }

            if ($now - $conn->getLastActivity() > min(5, $this->keepAliveTimeout)) {
                $this->closeConnection($conn);
                $closed++;
            }
        }
        $this->memoryPressureClosed += $closed;
    }

    private function pauseAccepting(): void
    {
        if (!$this->accepting || !$this->serverSocket || !\Nexphant\Server\Socket\SocketDriverFactory::isValidSocket($this->serverSocket)) {
            $this->accepting = false;
            return;
        }

        $this->accepting = false;
        $this->loop->removeReader($this->serverSocket);
    }

    private function resumeAccepting(): void
    {
        if ($this->accepting || $this->draining || !$this->serverSocket || !\Nexphant\Server\Socket\SocketDriverFactory::isValidSocket($this->serverSocket)) {
            return;
        }

        $this->accepting = true;
        $this->loop->addReader($this->serverSocket, function ($socket) {
            $this->acceptConnections();
        });
    }

    public function gracefulShutdown(): void
    {
        if ($this->draining) {
            return;
        }

        $this->draining = true;
        $this->drainStartedAt = microtime(true);
        $this->pauseAccepting();

        // Integrate with DrainController
        if (class_exists('\Nexphant\Core\Drain\DrainController')) {
            \Nexphant\Core\Drain\DrainController::instance()->stopAccepting();
        }

        foreach ($this->connections as $conn) {
            if (!$conn->isWebSocket() && !$conn->isSse()) {
                $conn->setKeepAlive(false);
            }
        }

        $this->checkDrain();
    }

    private function checkDrain(): void
    {
        if (!$this->draining) {
            return;
        }

        if (count($this->connections) === 0 || microtime(true) - $this->drainStartedAt >= $this->gracefulShutdownTimeout) {
            $this->shutdown();
        }
    }

    private function checkWebSocketHeartbeats(): void
    {
        if ($this->webSocketPingInterval <= 0) {
            return;
        }

        $now = microtime(true);
        foreach ($this->connections as $conn) {
            if (!$conn->isWebSocket() || !$conn->isAlive() || $conn->isClosing()) {
                continue;
            }

            $lastPong = $conn->getLastPongAt();
            $lastPing = $conn->getLastPingAt();
            if ($lastPing > $lastPong && $now - $lastPing > $this->webSocketPongTimeout) {
                $this->webSocketHeartbeatTimeouts++;
                $this->closeWebSocket($conn, 1001, 'Pong timeout');
                continue;
            }

            if ($now - max($lastPong, $lastPing, $conn->getLastReadAt()) >= $this->webSocketPingInterval) {
                $this->sendWebSocketPing($conn);
            }
        }
    }

    public function shutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;
        $this->log("Shutting down...");
        $this->publishStats();

        // Close server socket
        if ($this->serverSocket && \Nexphant\Server\Socket\SocketDriverFactory::isValidSocket($this->serverSocket)) {
            $this->loop->removeReader($this->serverSocket);
            if ($this->serverSocket instanceof \Socket) {
                @socket_close($this->serverSocket);
            } else {
                @fclose($this->serverSocket);
            }
        }
        $this->serverSocket = null;
        $this->accepting = false;

        // Close all connections
        foreach (array_values($this->connections) as $conn) {
            $this->closeConnection($conn);
        }

        if ($this->webSocketRedisBus) {
            $this->webSocketRedisBus->close();
        }
        if ($this->sseRedisBus) {
            $this->sseRedisBus->close();
        }
        $this->workerOwner?->close();
        $this->workerOwner = null;

        $this->loop->stop();
        $this->logStats();
        $this->removeStatsFile();
    }

    public function getStats(): array
    {
        $this->publishStats();
        return $this->aggregateStats();
    }

    public function getMetricsText(): string
    {
        $stats = $this->getStats();
        $lines = [
            '# HELP NEXPHANT_http_requests_total Total HTTP requests.',
            '# TYPE NEXPHANT_http_requests_total counter',
            'NEXPHANT_http_requests_total ' . (int) $stats['total_requests'],
            '# HELP NEXPHANT_http_active_requests Active HTTP requests.',
            '# TYPE NEXPHANT_http_active_requests gauge',
            'NEXPHANT_http_active_requests ' . (int) ($stats['active_requests'] ?? 0),
            '# HELP NEXPHANT_http_connections_total Total accepted connections.',
            '# TYPE NEXPHANT_http_connections_total counter',
            'NEXPHANT_http_connections_total ' . (int) $stats['total_connections'],
            '# HELP NEXPHANT_http_active_connections Active open connections.',
            '# TYPE NEXPHANT_http_active_connections gauge',
            'NEXPHANT_http_active_connections ' . (int) $stats['active_connections'],
            '# HELP NEXPHANT_websockets_total Total accepted WebSocket upgrades.',
            '# TYPE NEXPHANT_websockets_total counter',
            'NEXPHANT_websockets_total ' . (int) ($stats['total_websockets'] ?? 0),
            '# HELP NEXPHANT_active_websockets Active WebSocket connections.',
            '# TYPE NEXPHANT_active_websockets gauge',
            'NEXPHANT_active_websockets ' . (int) ($stats['active_websockets'] ?? 0),
            '# HELP NEXPHANT_sse_connections_total Total accepted SSE streams.',
            '# TYPE NEXPHANT_sse_connections_total counter',
            'NEXPHANT_sse_connections_total ' . (int) ($stats['total_sse_connections'] ?? 0),
            '# HELP NEXPHANT_sse_active_connections Active SSE streams.',
            '# TYPE NEXPHANT_sse_active_connections gauge',
            'NEXPHANT_sse_active_connections ' . (int) ($stats['active_sse_connections'] ?? 0),
            '# HELP NEXPHANT_sse_events_sent_total SSE events sent.',
            '# TYPE NEXPHANT_sse_events_sent_total counter',
            'NEXPHANT_sse_events_sent_total ' . (int) ($stats['sse']['events_sent'] ?? 0),
            '# HELP NEXPHANT_sse_heartbeats_total SSE heartbeat comments sent.',
            '# TYPE NEXPHANT_sse_heartbeats_total counter',
            'NEXPHANT_sse_heartbeats_total ' . (int) ($stats['sse']['heartbeats_sent'] ?? 0),
            '# HELP NEXPHANT_sse_backpressure_closes_total SSE connections closed by backpressure.',
            '# TYPE NEXPHANT_sse_backpressure_closes_total counter',
            'NEXPHANT_sse_backpressure_closes_total ' . (int) ($stats['sse']['backpressure_closes'] ?? 0),
            '# HELP NEXPHANT_sse_auth_failures_total SSE auth failures.',
            '# TYPE NEXPHANT_sse_auth_failures_total counter',
            'NEXPHANT_sse_auth_failures_total ' . (int) ($stats['sse']['auth_failures'] ?? 0),
            '# HELP NEXPHANT_sse_replay_requests_total SSE resume requests.',
            '# TYPE NEXPHANT_sse_replay_requests_total counter',
            'NEXPHANT_sse_replay_requests_total ' . (int) ($stats['sse']['replay_requests'] ?? 0),
            '# HELP NEXPHANT_sse_replayed_events_total SSE events replayed on resume.',
            '# TYPE NEXPHANT_sse_replayed_events_total counter',
            'NEXPHANT_sse_replayed_events_total ' . (int) ($stats['sse']['replayed_events'] ?? 0),
            '# HELP NEXPHANT_sse_replay_expired_total SSE resume requests with missing event id.',
            '# TYPE NEXPHANT_sse_replay_expired_total counter',
            'NEXPHANT_sse_replay_expired_total ' . (int) ($stats['sse']['replay_expired'] ?? 0),
            '# HELP NEXPHANT_sse_replay_buffer_events SSE replay buffer events.',
            '# TYPE NEXPHANT_sse_replay_buffer_events gauge',
            'NEXPHANT_sse_replay_buffer_events ' . (int) ($stats['sse']['replay_buffer_events'] ?? 0),
            '# HELP NEXPHANT_sse_broadcasts_total SSE broadcast calls.',
            '# TYPE NEXPHANT_sse_broadcasts_total counter',
            'NEXPHANT_sse_broadcasts_total ' . (int) ($stats['sse']['broadcasts'] ?? 0),
            '# HELP NEXPHANT_sse_local_deliveries_total SSE local event deliveries.',
            '# TYPE NEXPHANT_sse_local_deliveries_total counter',
            'NEXPHANT_sse_local_deliveries_total ' . (int) ($stats['sse']['local_deliveries'] ?? 0),
            '# HELP NEXPHANT_sse_bus_published_total SSE events published to cross-worker bus.',
            '# TYPE NEXPHANT_sse_bus_published_total counter',
            'NEXPHANT_sse_bus_published_total ' . (int) ($stats['sse']['bus_published'] ?? 0),
            '# HELP NEXPHANT_sse_bus_deliveries_total SSE deliveries from cross-worker bus.',
            '# TYPE NEXPHANT_sse_bus_deliveries_total counter',
            'NEXPHANT_sse_bus_deliveries_total ' . (int) ($stats['sse']['bus_deliveries'] ?? 0),
            '# HELP NEXPHANT_sse_bus_bytes SSE cross-worker bus file size.',
            '# TYPE NEXPHANT_sse_bus_bytes gauge',
            'NEXPHANT_sse_bus_bytes ' . (int) ($stats['sse']['bus_file_size'] ?? 0),
            '# HELP NEXPHANT_sse_bus_seen_events SSE bus events remembered for dedupe.',
            '# TYPE NEXPHANT_sse_bus_seen_events gauge',
            'NEXPHANT_sse_bus_seen_events ' . (int) ($stats['sse']['bus_seen_events'] ?? 0),
            '# HELP NEXPHANT_websocket_pings_total WebSocket pings sent.',
            '# TYPE NEXPHANT_websocket_pings_total counter',
            'NEXPHANT_websocket_pings_total ' . (int) ($stats['websocket_heartbeat']['pings_sent'] ?? 0),
            '# HELP NEXPHANT_websocket_pongs_total WebSocket pongs received.',
            '# TYPE NEXPHANT_websocket_pongs_total counter',
            'NEXPHANT_websocket_pongs_total ' . (int) ($stats['websocket_heartbeat']['pongs_received'] ?? 0),
            '# HELP NEXPHANT_websocket_heartbeat_timeouts_total WebSocket heartbeat timeouts.',
            '# TYPE NEXPHANT_websocket_heartbeat_timeouts_total counter',
            'NEXPHANT_websocket_heartbeat_timeouts_total ' . (int) ($stats['websocket_heartbeat']['heartbeat_timeouts'] ?? 0),
            '# HELP NEXPHANT_websocket_idle_closes_total WebSocket idle closes.',
            '# TYPE NEXPHANT_websocket_idle_closes_total counter',
            'NEXPHANT_websocket_idle_closes_total ' . (int) ($stats['websocket_heartbeat']['idle_closes'] ?? 0),
            '# HELP NEXPHANT_websocket_bus_bytes WebSocket cross-worker bus file size.',
            '# TYPE NEXPHANT_websocket_bus_bytes gauge',
            'NEXPHANT_websocket_bus_bytes ' . (int) ($stats['websocket_bus']['file_size'] ?? 0),
            '# HELP NEXPHANT_websocket_broadcasts_total WebSocket broadcast calls.',
            '# TYPE NEXPHANT_websocket_broadcasts_total counter',
            'NEXPHANT_websocket_broadcasts_total ' . (int) ($stats['websocket_broadcast']['broadcasts'] ?? 0),
            '# HELP NEXPHANT_websocket_broadcast_deliveries_total WebSocket broadcast frame deliveries.',
            '# TYPE NEXPHANT_websocket_broadcast_deliveries_total counter',
            'NEXPHANT_websocket_broadcast_deliveries_total ' . (int) ($stats['websocket_broadcast']['deliveries'] ?? 0),
            '# HELP NEXPHANT_websocket_broadcast_batches_total WebSocket broadcast delivery batches.',
            '# TYPE NEXPHANT_websocket_broadcast_batches_total counter',
            'NEXPHANT_websocket_broadcast_batches_total ' . (int) ($stats['websocket_broadcast']['batches'] ?? 0),
            '# HELP NEXPHANT_websocket_broadcast_pending_batches Pending WebSocket broadcast batches.',
            '# TYPE NEXPHANT_websocket_broadcast_pending_batches gauge',
            'NEXPHANT_websocket_broadcast_pending_batches ' . (int) ($stats['websocket_broadcast']['pending_batches'] ?? 0),
            '# HELP NEXPHANT_websocket_backpressure_skips_total WebSocket frames skipped by backpressure.',
            '# TYPE NEXPHANT_websocket_backpressure_skips_total counter',
            'NEXPHANT_websocket_backpressure_skips_total ' . (int) ($stats['websocket_backpressure']['skips'] ?? 0),
            '# HELP NEXPHANT_websocket_backpressure_closes_total WebSocket connections closed by backpressure.',
            '# TYPE NEXPHANT_websocket_backpressure_closes_total counter',
            'NEXPHANT_websocket_backpressure_closes_total ' . (int) ($stats['websocket_backpressure']['closes'] ?? 0),
            '# HELP NEXPHANT_websocket_read_limit_closes_total WebSocket connections closed by read buffer limit.',
            '# TYPE NEXPHANT_websocket_read_limit_closes_total counter',
            'NEXPHANT_websocket_read_limit_closes_total ' . (int) ($stats['websocket_limits']['read_limit_closes'] ?? 0),
            '# HELP NEXPHANT_websocket_frame_limit_closes_total WebSocket connections closed by frame size limit.',
            '# TYPE NEXPHANT_websocket_frame_limit_closes_total counter',
            'NEXPHANT_websocket_frame_limit_closes_total ' . (int) ($stats['websocket_limits']['frame_limit_closes'] ?? 0),
            '# HELP NEXPHANT_websocket_max_frame_bytes WebSocket max frame payload bytes.',
            '# TYPE NEXPHANT_websocket_max_frame_bytes gauge',
            'NEXPHANT_websocket_max_frame_bytes ' . (int) ($stats['websocket_limits']['max_frame_size'] ?? 0),
            '# HELP NEXPHANT_websocket_max_read_buffer_bytes WebSocket max read buffer bytes.',
            '# TYPE NEXPHANT_websocket_max_read_buffer_bytes gauge',
            'NEXPHANT_websocket_max_read_buffer_bytes ' . (int) ($stats['websocket_limits']['max_read_buffer_size'] ?? 0),
            '# HELP NEXPHANT_websocket_rooms Active WebSocket rooms.',
            '# TYPE NEXPHANT_websocket_rooms gauge',
            'NEXPHANT_websocket_rooms ' . (int) ($stats['websocket_rooms']['rooms'] ?? 0),
            '# HELP NEXPHANT_websocket_room_memberships Active WebSocket room memberships.',
            '# TYPE NEXPHANT_websocket_room_memberships gauge',
            'NEXPHANT_websocket_room_memberships ' . (int) ($stats['websocket_rooms']['memberships'] ?? 0),
            '# HELP NEXPHANT_connection_write_buffer_limit_bytes Per-connection write buffer limit.',
            '# TYPE NEXPHANT_connection_write_buffer_limit_bytes gauge',
            'NEXPHANT_connection_write_buffer_limit_bytes ' . $this->maxWriteBufferSize,
            '# HELP NEXPHANT_connection_write_buffer_bytes Active write buffer bytes.',
            '# TYPE NEXPHANT_connection_write_buffer_bytes gauge',
            'NEXPHANT_connection_write_buffer_bytes ' . (int) ($stats['write_buffer']['bytes'] ?? 0),
            '# HELP NEXPHANT_connection_write_buffer_max_bytes Max active connection write buffer bytes.',
            '# TYPE NEXPHANT_connection_write_buffer_max_bytes gauge',
            'NEXPHANT_connection_write_buffer_max_bytes ' . (int) ($stats['write_buffer']['max_bytes'] ?? 0),
            '# HELP NEXPHANT_runtime_coroutines Active server coroutines.',
            '# TYPE NEXPHANT_runtime_coroutines gauge',
            'NEXPHANT_runtime_coroutines ' . (int) $stats['coroutines'],
            '# HELP NEXPHANT_runtime_memory_bytes Current runtime memory.',
            '# TYPE NEXPHANT_runtime_memory_bytes gauge',
            'NEXPHANT_runtime_memory_bytes ' . (int) ($stats['memory']['current'] ?? 0),
            '# HELP NEXPHANT_runtime_memory_peak_bytes Peak runtime memory.',
            '# TYPE NEXPHANT_runtime_memory_peak_bytes gauge',
            'NEXPHANT_runtime_memory_peak_bytes ' . (int) ($stats['memory']['peak'] ?? 0),
            '# HELP NEXPHANT_runtime_loop_lag_ms Event loop lag EWMA.',
            '# TYPE NEXPHANT_runtime_loop_lag_ms gauge',
            'NEXPHANT_runtime_loop_lag_ms ' . $this->metricFloat($stats['loop']['lag_ms'] ?? 0),
            '# HELP NEXPHANT_runtime_loop_lag_max_ms Event loop max lag.',
            '# TYPE NEXPHANT_runtime_loop_lag_max_ms gauge',
            'NEXPHANT_runtime_loop_lag_max_ms ' . $this->metricFloat($stats['loop']['lag_max_ms'] ?? 0),
            '# HELP NEXPHANT_runtime_deferred_dropped_total Deferred callbacks dropped by queue limit.',
            '# TYPE NEXPHANT_runtime_deferred_dropped_total counter',
            'NEXPHANT_runtime_deferred_dropped_total ' . (int) ($stats['loop']['deferred_dropped'] ?? 0),
            '# HELP NEXPHANT_runtime_deferred_limit Deferred callback queue limit.',
            '# TYPE NEXPHANT_runtime_deferred_limit gauge',
            'NEXPHANT_runtime_deferred_limit ' . (int) ($stats['loop']['deferred_limit'] ?? 0),
            '# HELP NEXPHANT_runtime_memory_pressure_events_total Memory pressure state changes.',
            '# TYPE NEXPHANT_runtime_memory_pressure_events_total counter',
            'NEXPHANT_runtime_memory_pressure_events_total ' . (int) ($stats['memory_pressure']['events'] ?? 0),
            '# HELP NEXPHANT_runtime_memory_pressure_rejected_total Connections rejected under memory pressure.',
            '# TYPE NEXPHANT_runtime_memory_pressure_rejected_total counter',
            'NEXPHANT_runtime_memory_pressure_rejected_total ' . (int) ($stats['memory_pressure']['rejected'] ?? 0),
            '# HELP NEXPHANT_runtime_memory_pressure_closed_total Connections closed under memory pressure.',
            '# TYPE NEXPHANT_runtime_memory_pressure_closed_total counter',
            'NEXPHANT_runtime_memory_pressure_closed_total ' . (int) ($stats['memory_pressure']['closed'] ?? 0),
            '# HELP NEXPHANT_runtime_workers_reporting Workers publishing stats.',
            '# TYPE NEXPHANT_runtime_workers_reporting gauge',
            'NEXPHANT_runtime_workers_reporting ' . (int) ($stats['workers_reporting'] ?? 0),
            '# HELP NEXPHANT_object_pool_idle Idle pooled objects.',
            '# TYPE NEXPHANT_object_pool_idle gauge',
            'NEXPHANT_object_pool_idle{pool="request"} ' . (int) ($stats['pools']['request']['idle'] ?? 0),
            'NEXPHANT_object_pool_idle{pool="response"} ' . (int) ($stats['pools']['response']['idle'] ?? 0),
            'NEXPHANT_object_pool_idle{pool="buffer"} ' . (int) ($stats['pools']['buffer']['idle'] ?? 0),
            '# HELP NEXPHANT_object_pool_reused_total Reused pooled objects.',
            '# TYPE NEXPHANT_object_pool_reused_total counter',
            'NEXPHANT_object_pool_reused_total{pool="request"} ' . (int) ($stats['pools']['request']['reused'] ?? 0),
            'NEXPHANT_object_pool_reused_total{pool="response"} ' . (int) ($stats['pools']['response']['reused'] ?? 0),
            'NEXPHANT_object_pool_reused_total{pool="buffer"} ' . (int) ($stats['pools']['buffer']['reused'] ?? 0),
            '# HELP NEXPHANT_object_pool_created_total Created pooled objects.',
            '# TYPE NEXPHANT_object_pool_created_total counter',
            'NEXPHANT_object_pool_created_total{pool="request"} ' . (int) ($stats['pools']['request']['created'] ?? 0),
            'NEXPHANT_object_pool_created_total{pool="response"} ' . (int) ($stats['pools']['response']['created'] ?? 0),
            'NEXPHANT_object_pool_created_total{pool="buffer"} ' . (int) ($stats['pools']['buffer']['created'] ?? 0),
            '# HELP NEXPHANT_object_pool_borrowed Active borrowed pooled objects.',
            '# TYPE NEXPHANT_object_pool_borrowed gauge',
            'NEXPHANT_object_pool_borrowed{pool="request"} ' . (int) ($stats['pools']['request']['borrowed'] ?? 0),
            'NEXPHANT_object_pool_borrowed{pool="response"} ' . (int) ($stats['pools']['response']['borrowed'] ?? 0),
            'NEXPHANT_object_pool_borrowed{pool="buffer"} ' . (int) ($stats['pools']['buffer']['borrowed'] ?? 0),
            '# HELP NEXPHANT_object_pool_violations_total Pool safety violations.',
            '# TYPE NEXPHANT_object_pool_violations_total counter',
            'NEXPHANT_object_pool_violations_total{pool="response",type="foreign_release"} ' . (int) ($stats['pools']['response']['foreign_release'] ?? 0),
            'NEXPHANT_object_pool_violations_total{pool="response",type="double_release"} ' . (int) ($stats['pools']['response']['double_release'] ?? 0),
            'NEXPHANT_object_pool_violations_total{pool="response",type="contamination"} ' . (int) ($stats['pools']['response']['contamination'] ?? 0),
            '# HELP NEXPHANT_object_tracker_active Runtime tracked live objects.',
            '# TYPE NEXPHANT_object_tracker_active gauge',
            'NEXPHANT_object_tracker_active ' . (int) ($stats['object_tracker']['active'] ?? 0),
            '# HELP NEXPHANT_object_tracker_retained Runtime objects retained after context close.',
            '# TYPE NEXPHANT_object_tracker_retained gauge',
            'NEXPHANT_object_tracker_retained ' . (int) ($stats['object_tracker']['retained'] ?? 0),
            '# HELP NEXPHANT_object_tracker_released_alive Runtime released objects still alive.',
            '# TYPE NEXPHANT_object_tracker_released_alive gauge',
            'NEXPHANT_object_tracker_released_alive ' . (int) ($stats['object_tracker']['released_alive'] ?? 0),
            '# HELP NEXPHANT_runtime_contexts_open Open runtime contexts.',
            '# TYPE NEXPHANT_runtime_contexts_open gauge',
            'NEXPHANT_runtime_contexts_open ' . (int) ($stats['object_tracker']['contexts']['open'] ?? 0),
        ];

        $http = $stats['http'] ?? [];
        $lines[] = '# HELP NEXPHANT_http_responses_total HTTP responses by status.';
        $lines[] = '# TYPE NEXPHANT_http_responses_total counter';
        foreach ($http['status_counts'] ?? [] as $status => $count) {
            $lines[] = 'NEXPHANT_http_responses_total{status="' . $this->metricLabel((string) $status) . '"} ' . (int) $count;
        }

        $lines[] = '# HELP NEXPHANT_http_route_requests_total HTTP requests by normalized route.';
        $lines[] = '# TYPE NEXPHANT_http_route_requests_total counter';
        foreach ($http['route_counts'] ?? [] as $route => $count) {
            [$method, $path] = array_pad(explode(' ', (string) $route, 2), 2, 'unknown');
            $lines[] = 'NEXPHANT_http_route_requests_total{method="' . $this->metricLabel($method) . '",path="' . $this->metricLabel($path) . '"} ' . (int) $count;
        }
        foreach ($http['route_latency'] ?? [] as $route => $latency) {
            [$method, $path] = array_pad(explode(' ', (string) $route, 2), 2, 'unknown');
            $labels = '{method="' . $this->metricLabel($method) . '",path="' . $this->metricLabel($path) . '"}';
            $lines[] = 'NEXPHANT_http_route_request_duration_p95_ms' . $labels . ' ' . $this->metricFloat($latency['p95'] ?? 0);
            $lines[] = 'NEXPHANT_http_route_request_duration_p99_ms' . $labels . ' ' . $this->metricFloat($latency['p99'] ?? 0);
        }

        $lines[] = '# HELP NEXPHANT_http_request_duration_ms HTTP request duration in milliseconds.';
        $lines[] = '# TYPE NEXPHANT_http_request_duration_ms histogram';
        foreach ($http['latency_buckets_ms'] ?? [] as $bucket => $count) {
            $lines[] = 'NEXPHANT_http_request_duration_ms_bucket{le="' . $this->metricLabel((string) $bucket) . '"} ' . (int) $count;
        }
        $lines[] = 'NEXPHANT_http_request_duration_ms_sum ' . $this->metricFloat($http['latency_sum_ms'] ?? 0);
        $lines[] = 'NEXPHANT_http_request_duration_ms_count ' . (int) ($http['latency_count'] ?? 0);
        $lines[] = 'NEXPHANT_http_request_duration_ms_max ' . $this->metricFloat($http['latency_max_ms'] ?? 0);

        foreach ($stats['workers'] ?? [] as $worker) {
            $id = (int) ($worker['worker_id'] ?? 0);
            $labels = '{worker_id="' . $id . '",pid="' . (int) ($worker['pid'] ?? 0) . '"}';
            $loop = $worker['loop'] ?? [];
            $lines[] = 'NEXPHANT_worker_http_requests_total' . $labels . ' ' . (int) ($worker['total_requests'] ?? 0);
            $lines[] = 'NEXPHANT_worker_active_connections' . $labels . ' ' . (int) ($worker['active_connections'] ?? 0);
            $lines[] = 'NEXPHANT_worker_active_websockets' . $labels . ' ' . (int) ($worker['active_websockets'] ?? 0);
            $lines[] = 'NEXPHANT_worker_active_sse_connections' . $labels . ' ' . (int) ($worker['active_sse_connections'] ?? 0);
            $lines[] = 'NEXPHANT_worker_coroutines' . $labels . ' ' . (int) ($worker['coroutines'] ?? 0);
            $lines[] = 'NEXPHANT_worker_loop_lag_ms' . $labels . ' ' . $this->metricFloat($loop['lag_ms'] ?? 0);
            $lines[] = 'NEXPHANT_worker_loop_readers' . $labels . ' ' . (int) ($loop['readers'] ?? 0);
            $lines[] = 'NEXPHANT_worker_loop_writers' . $labels . ' ' . (int) ($loop['writers'] ?? 0);
        }

        return implode("\n", $lines) . "\n";
    }

    private function getLocalStats(): array
    {
        return [
            'pid' => getmypid(),
            'worker_id' => $this->workerId,
            'worker_count' => $this->workerCount,
            'updated_at' => microtime(true),
            'uptime' => microtime(true) - $this->startTime,
            'total_requests' => $this->totalRequests,
            'total_connections' => $this->totalConnections,
            'total_websockets' => $this->totalWebSockets,
            'total_sse_connections' => $this->totalSseConnections,
            'websocket_heartbeat' => [
                'ping_interval' => $this->webSocketPingInterval,
                'pong_timeout' => $this->webSocketPongTimeout,
                'pings_sent' => $this->webSocketPingsSent,
                'pongs_received' => $this->webSocketPongsReceived,
                'heartbeat_timeouts' => $this->webSocketHeartbeatTimeouts,
                'idle_closes' => $this->webSocketIdleCloses,
            ],
            'active_requests' => $this->activeRequests,
            'active_connections' => count($this->connections),
            'active_websockets' => $this->countWebSockets(),
            'active_sse_connections' => $this->countSseConnections(),
            'sse' => [
                'heartbeat_interval' => $this->sseHeartbeatInterval,
                'timeout' => $this->sseTimeout,
                'bus_type' => $this->sseBusType,
                'events_sent' => $this->sseEventsSent,
                'heartbeats_sent' => $this->sseHeartbeatsSent,
                'backpressure_closes' => $this->sseBackpressureCloses,
                'auth_failures' => $this->sseAuthFailures,
                'replay_requests' => $this->sseReplayRequests,
                'replayed_events' => $this->sseReplayedEvents,
                'replay_expired' => $this->sseReplayExpired,
                'replay_limit' => $this->sseReplayLimit,
                'replay_buffer_events' => $this->countSseReplayEvents(),
                'broadcasts' => $this->sseBroadcasts,
                'local_deliveries' => $this->sseLocalDeliveries,
                'bus_published' => $this->sseBusPublished,
                'bus_deliveries' => $this->sseBusDeliveries,
                'bus_file_size' => is_file($this->sseBusFile) ? (int) filesize($this->sseBusFile) : 0,
                'bus_offset' => $this->sseBusOffset,
                'bus_seen_events' => count($this->sseSeenEvents),
                'redis' => $this->sseRedisBus ? $this->sseRedisBus->stats() : null,
            ],
            'websocket_bus' => [
                'type' => $this->webSocketBusType,
                'file_size' => is_file($this->webSocketBusFile) ? (int) filesize($this->webSocketBusFile) : 0,
                'offset' => $this->webSocketBusOffset,
                'seen_events' => count($this->webSocketSeenEvents),
                'redis' => $this->webSocketRedisBus ? $this->webSocketRedisBus->stats() : null,
            ],
            'websocket_broadcast' => [
                'broadcasts' => $this->webSocketBroadcasts,
                'deliveries' => $this->webSocketBroadcastDeliveries,
                'batches' => $this->webSocketBroadcastBatches,
                'pending_batches' => $this->webSocketPendingBroadcastBatches,
            ],
            'websocket_backpressure' => [
                'policy' => $this->webSocketBackpressurePolicy,
                'soft_limit' => $this->webSocketBackpressureSoftLimit,
                'skips' => $this->webSocketBackpressureSkips,
                'closes' => $this->webSocketBackpressureCloses,
            ],
            'websocket_limits' => [
                'max_frame_size' => $this->webSocketMaxFrameSize,
                'max_read_buffer_size' => $this->webSocketMaxReadBufferSize,
                'read_limit_closes' => $this->webSocketReadLimitCloses,
                'frame_limit_closes' => $this->webSocketFrameLimitCloses,
            ],
            'websocket_rooms' => $this->getWebSocketRoomStats(),
            'pools' => [
                'request' => $this->requestPool->stats(),
                'response' => $this->responsePool->stats(),
                'buffer' => $this->bufferPool->stats(),
            ],
            'object_tracker' => $this->objectTracker->stats(),
            'http' => [
                'status_counts' => $this->httpStatusCounts,
                'route_counts' => $this->httpRouteCounts,
                'route_latency' => $this->routeLatencyStats(),
                'latency_buckets_ms' => $this->httpLatencyBuckets,
                'latency_count' => $this->httpLatencyCount,
                'latency_sum_ms' => $this->httpLatencySumMs,
                'latency_max_ms' => $this->httpLatencyMaxMs,
            ],
            'memory' => $this->memoryMonitor->getStats(),
            'write_buffer' => $this->writeBufferStats(),
            'memory_pressure' => [
                'state' => $this->memoryPressureState,
                'limit' => $this->memoryLimit,
                'threshold' => $this->memoryPressureThreshold,
                'hard_threshold' => $this->memoryHardPressureThreshold,
                'events' => $this->memoryPressureEvents,
                'rejected' => $this->memoryPressureRejected,
                'closed' => $this->memoryPressureClosed,
            ],
            'coroutines' => Coroutine::count(),
            'loop' => [
                'lag_ms' => $this->loopLagMs,
                'lag_max_ms' => $this->loopLagMaxMs,
                'readers' => $this->loop->getReaderCount(),
                'writers' => $this->loop->getWriterCount(),
                'timers' => $this->loop->getTimerCount(),
                'deferred' => $this->loop->getDeferredCount(),
                'deferred_limit' => $this->loop->getMaxDeferred(),
                'deferred_dropped' => $this->loop->getDeferredDroppedCount(),
                'ticks' => $this->loop->getTickCount(),
                'last_tick_ms' => $this->loop->getLastTickDurationMs(),
            ],
            'lifecycle' => [
                'accepting' => $this->accepting,
                'draining' => $this->draining,
                'drain_started_at' => $this->drainStartedAt,
            ],
        ];
    }

    private function prepareStatsDir(): void
    {
        if (!is_dir($this->statsDir)) {
            @mkdir($this->statsDir, 0775, true);
        }
    }

    private function publishStats(): void
    {
        $this->prepareStatsDir();

        $file = $this->statsFile();
        $tmp = $file . '.tmp';
        $data = json_encode($this->getLocalStats(), JSON_UNESCAPED_SLASHES);

        if ($data === false) {
            return;
        }

        if (@file_put_contents($tmp, $data, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    private function aggregateStats(): array
    {
        $now = microtime(true);
        $workers = [];
        $memoryCurrent = 0;
        $memoryPeak = 0;
        $sampleCount = 0;
        $memoryPressure = [
            'events' => 0,
            'rejected' => 0,
            'closed' => 0,
        ];
        $totalRequests = 0;
        $totalConnections = 0;
        $totalWebSockets = 0;
        $totalSseConnections = 0;
        $webSocketHeartbeat = [
            'ping_interval' => $this->webSocketPingInterval,
            'pong_timeout' => $this->webSocketPongTimeout,
            'pings_sent' => 0,
            'pongs_received' => 0,
            'heartbeat_timeouts' => 0,
            'idle_closes' => 0,
        ];
        $activeRequests = 0;
        $activeConnections = 0;
        $activeWebSockets = 0;
        $activeSseConnections = 0;
        $sse = [
            'heartbeat_interval' => $this->sseHeartbeatInterval,
            'timeout' => $this->sseTimeout,
            'bus_type' => $this->sseBusType,
            'events_sent' => 0,
            'heartbeats_sent' => 0,
            'backpressure_closes' => 0,
            'auth_failures' => 0,
            'replay_requests' => 0,
            'replayed_events' => 0,
            'replay_expired' => 0,
            'replay_limit' => $this->sseReplayLimit,
            'replay_buffer_events' => 0,
            'broadcasts' => 0,
            'local_deliveries' => 0,
            'bus_published' => 0,
            'bus_deliveries' => 0,
            'bus_file_size' => 0,
            'bus_offset' => 0,
            'bus_seen_events' => 0,
        ];
        $webSocketBusSize = 0;
        $webSocketBroadcast = [
            'broadcasts' => 0,
            'deliveries' => 0,
            'batches' => 0,
            'pending_batches' => 0,
        ];
        $webSocketBackpressure = [
            'skips' => 0,
            'closes' => 0,
        ];
        $webSocketLimits = [
            'max_frame_size' => $this->webSocketMaxFrameSize,
            'max_read_buffer_size' => $this->webSocketMaxReadBufferSize,
            'read_limit_closes' => 0,
            'frame_limit_closes' => 0,
        ];
        $webSocketRooms = [
            'rooms' => 0,
            'memberships' => 0,
        ];
        $emptyPool = [
            'idle' => 0,
            'borrowed' => 0,
            'max' => 0,
            'created' => 0,
            'reused' => 0,
            'released' => 0,
            'dropped' => 0,
            'foreign_release' => 0,
            'double_release' => 0,
            'contamination' => 0,
        ];
        $requestPool = $emptyPool;
        $responsePool = $emptyPool;
        $bufferPool = $emptyPool;
        $objectTracker = [
            'active' => 0,
            'tracked_total' => 0,
            'released_total' => 0,
            'retained' => 0,
            'released_alive' => 0,
            'by_type' => [],
            'by_state' => [],
            'contexts' => [
                'open' => 0,
                'stale_closed' => 0,
                'opened_total' => 0,
                'closed_total' => 0,
            ],
        ];
        $coroutines = 0;
        $httpStatusCounts = [];
        $httpRouteCounts = [];
        $httpLatencyBuckets = array_fill_keys(array_merge(array_map('strval', self::LATENCY_BUCKETS_MS), ['+Inf']), 0);
        $httpLatencyCount = 0;
        $httpLatencySumMs = 0.0;
        $httpLatencyMaxMs = 0.0;
        $httpRouteLatency = [];
        $writeBuffer = ['bytes' => 0, 'max_bytes' => 0, 'connections' => 0];
        $uptime = 0.0;
        $loopLagMs = 0.0;
        $loopLagMaxMs = 0.0;
        $loopReaders = 0;
        $loopWriters = 0;
        $loopTimers = 0;
        $loopDeferred = 0;
        $loopDeferredLimit = 0;
        $loopDeferredDropped = 0;
        $loopTicks = 0;
        $loopLastTickMs = 0.0;

        foreach (glob($this->statsDir . '/worker-*.json') ?: [] as $file) {
            $json = @file_get_contents($file);
            $stats = $json ? json_decode($json, true) : null;
            if (!is_array($stats) || ($now - (float) ($stats['updated_at'] ?? 0)) > 15) {
                @unlink($file);
                continue;
            }

            $workers[] = $stats;
            $totalRequests += (int) ($stats['total_requests'] ?? 0);
            $totalConnections += (int) ($stats['total_connections'] ?? 0);
            $totalWebSockets += (int) ($stats['total_websockets'] ?? 0);
            $totalSseConnections += (int) ($stats['total_sse_connections'] ?? 0);
            $webSocketHeartbeat = $this->mergeCounterMap($webSocketHeartbeat, $stats['websocket_heartbeat'] ?? []);
            $activeRequests += (int) ($stats['active_requests'] ?? 0);
            $activeConnections += (int) ($stats['active_connections'] ?? 0);
            $activeWebSockets += (int) ($stats['active_websockets'] ?? 0);
            $activeSseConnections += (int) ($stats['active_sse_connections'] ?? 0);
            $workerSse = $stats['sse'] ?? [];
            $sse['heartbeat_interval'] = max((int) $sse['heartbeat_interval'], (int) ($workerSse['heartbeat_interval'] ?? 0));
            $sse['timeout'] = max((int) $sse['timeout'], (int) ($workerSse['timeout'] ?? 0));
            $sse['bus_type'] = (string) ($workerSse['bus_type'] ?? $sse['bus_type']);
            foreach (['events_sent', 'heartbeats_sent', 'backpressure_closes', 'auth_failures', 'replay_requests', 'replayed_events', 'replay_expired', 'replay_buffer_events', 'broadcasts', 'local_deliveries', 'bus_published', 'bus_deliveries'] as $key) {
                $sse[$key] += (int) ($workerSse[$key] ?? 0);
            }
            $sse['replay_limit'] = max((int) $sse['replay_limit'], (int) ($workerSse['replay_limit'] ?? 0));
            $sse['bus_file_size'] = max((int) $sse['bus_file_size'], (int) ($workerSse['bus_file_size'] ?? 0));
            $sse['bus_offset'] = max((int) $sse['bus_offset'], (int) ($workerSse['bus_offset'] ?? 0));
            $sse['bus_seen_events'] += (int) ($workerSse['bus_seen_events'] ?? 0);
            $webSocketBusSize = max($webSocketBusSize, (int) ($stats['websocket_bus']['file_size'] ?? 0));
            $webSocketBroadcast = $this->mergeCounterMap($webSocketBroadcast, $stats['websocket_broadcast'] ?? []);
            $webSocketBackpressure = $this->mergeCounterMap($webSocketBackpressure, $stats['websocket_backpressure'] ?? []);
            $limits = $stats['websocket_limits'] ?? [];
            $webSocketLimits['read_limit_closes'] += (int) ($limits['read_limit_closes'] ?? 0);
            $webSocketLimits['frame_limit_closes'] += (int) ($limits['frame_limit_closes'] ?? 0);
            $webSocketLimits['max_frame_size'] = max($webSocketLimits['max_frame_size'], (int) ($limits['max_frame_size'] ?? 0));
            $webSocketLimits['max_read_buffer_size'] = max($webSocketLimits['max_read_buffer_size'], (int) ($limits['max_read_buffer_size'] ?? 0));
            $webSocketRooms = $this->mergeCounterMap($webSocketRooms, $stats['websocket_rooms'] ?? []);
            $requestPool = $this->mergeCounterMap($requestPool, $stats['pools']['request'] ?? []);
            $responsePool = $this->mergeCounterMap($responsePool, $stats['pools']['response'] ?? []);
            $bufferPool = $this->mergeCounterMap($bufferPool, $stats['pools']['buffer'] ?? []);
            $objectTracker = $this->mergeObjectTrackerStats($objectTracker, $stats['object_tracker'] ?? []);
            $coroutines += (int) ($stats['coroutines'] ?? 0);
            $http = $stats['http'] ?? [];
            $httpStatusCounts = $this->mergeCounterMap($httpStatusCounts, $http['status_counts'] ?? []);
            $httpRouteCounts = $this->mergeCounterMap($httpRouteCounts, $http['route_counts'] ?? []);
            foreach ($http['route_latency'] ?? [] as $route => $latency) {
                $httpRouteLatency[$route] ??= ['p95' => 0.0, 'p99' => 0.0, 'max' => 0.0, 'samples' => 0];
                $httpRouteLatency[$route]['p95'] = max($httpRouteLatency[$route]['p95'], (float) ($latency['p95'] ?? 0));
                $httpRouteLatency[$route]['p99'] = max($httpRouteLatency[$route]['p99'], (float) ($latency['p99'] ?? 0));
                $httpRouteLatency[$route]['max'] = max($httpRouteLatency[$route]['max'], (float) ($latency['max'] ?? 0));
                $httpRouteLatency[$route]['samples'] += (int) ($latency['samples'] ?? 0);
            }
            $httpLatencyBuckets = $this->mergeCounterMap($httpLatencyBuckets, $http['latency_buckets_ms'] ?? []);
            $httpLatencyCount += (int) ($http['latency_count'] ?? 0);
            $httpLatencySumMs += (float) ($http['latency_sum_ms'] ?? 0);
            $httpLatencyMaxMs = max($httpLatencyMaxMs, (float) ($http['latency_max_ms'] ?? 0));
            $uptime = max($uptime, (float) ($stats['uptime'] ?? 0));
            $memory = $stats['memory'] ?? [];
            $memoryCurrent += (int) ($memory['current'] ?? 0);
            $memoryPeak += (int) ($memory['peak'] ?? 0);
            $sampleCount += (int) ($memory['samples'] ?? 0);
            $memoryPressure = $this->mergeCounterMap($memoryPressure, array_intersect_key($stats['memory_pressure'] ?? [], $memoryPressure));
            $workerWrite = $stats['write_buffer'] ?? [];
            $writeBuffer['bytes'] += (int) ($workerWrite['bytes'] ?? 0);
            $writeBuffer['max_bytes'] = max($writeBuffer['max_bytes'], (int) ($workerWrite['max_bytes'] ?? 0));
            $writeBuffer['connections'] += (int) ($workerWrite['connections'] ?? 0);
            $loop = $stats['loop'] ?? [];
            $loopLagMs = max($loopLagMs, (float) ($loop['lag_ms'] ?? 0));
            $loopLagMaxMs = max($loopLagMaxMs, (float) ($loop['lag_max_ms'] ?? 0));
            $loopReaders += (int) ($loop['readers'] ?? 0);
            $loopWriters += (int) ($loop['writers'] ?? 0);
            $loopTimers += (int) ($loop['timers'] ?? 0);
            $loopDeferred += (int) ($loop['deferred'] ?? 0);
            $loopDeferredDropped += (int) ($loop['deferred_dropped'] ?? 0);
            $loopDeferredLimit = max($loopDeferredLimit, (int) ($loop['deferred_limit'] ?? 0));
            $loopTicks += (int) ($loop['ticks'] ?? 0);
            $loopLastTickMs = max($loopLastTickMs, (float) ($loop['last_tick_ms'] ?? 0));
        }

        usort($workers, fn($a, $b) => ($a['worker_id'] ?? 0) <=> ($b['worker_id'] ?? 0));

        return [
            'aggregate' => true,
            'worker_count' => $this->workerCount,
            'workers_reporting' => count($workers),
            'uptime' => $uptime,
            'total_requests' => $totalRequests,
            'total_connections' => $totalConnections,
            'total_websockets' => $totalWebSockets,
            'total_sse_connections' => $totalSseConnections,
            'websocket_heartbeat' => $webSocketHeartbeat,
            'active_requests' => $activeRequests,
            'active_connections' => $activeConnections,
            'active_websockets' => $activeWebSockets,
            'active_sse_connections' => $activeSseConnections,
            'sse' => $sse,
            'websocket_bus' => [
                'file_size' => $webSocketBusSize,
            ],
            'websocket_broadcast' => $webSocketBroadcast,
            'websocket_backpressure' => $webSocketBackpressure,
            'websocket_limits' => $webSocketLimits,
            'websocket_rooms' => $webSocketRooms,
            'pools' => [
                'request' => $requestPool,
                'response' => $responsePool,
                'buffer' => $bufferPool,
            ],
            'object_tracker' => $objectTracker,
            'http' => [
                'status_counts' => $httpStatusCounts,
                'route_counts' => $httpRouteCounts,
                'route_latency' => $httpRouteLatency,
                'latency_buckets_ms' => $httpLatencyBuckets,
                'latency_count' => $httpLatencyCount,
                'latency_sum_ms' => $httpLatencySumMs,
                'latency_max_ms' => $httpLatencyMaxMs,
            ],
            'memory' => [
                'current' => $memoryCurrent,
                'peak' => $memoryPeak,
                'samples' => $sampleCount,
                'trend' => $this->aggregateMemoryTrend($workers),
            ],
            'write_buffer' => $writeBuffer,
            'memory_pressure' => $memoryPressure,
            'coroutines' => $coroutines,
            'loop' => [
                'lag_ms' => $loopLagMs,
                'lag_max_ms' => $loopLagMaxMs,
                'readers' => $loopReaders,
                'writers' => $loopWriters,
                'timers' => $loopTimers,
                'deferred' => $loopDeferred,
                'deferred_limit' => $loopDeferredLimit,
                'deferred_dropped' => $loopDeferredDropped,
                'ticks' => $loopTicks,
                'last_tick_ms' => $loopLastTickMs,
            ],
            'workers' => $workers,
        ];
    }

    private function metricFloat(mixed $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.') ?: '0';
    }

    private function metricLabel(string $value): string
    {
        return str_replace(["\\", "\n", '"'], ["\\\\", "\\n", '\\"'], $value);
    }

    private function mergeCounterMap(array $base, array $next): array
    {
        foreach ($next as $key => $value) {
            $base[(string) $key] = ($base[(string) $key] ?? 0) + (int) $value;
        }
        return $base;
    }

    private function mergeObjectTrackerStats(array $base, array $next): array
    {
        foreach (['active', 'tracked_total', 'released_total', 'retained', 'released_alive'] as $key) {
            $base[$key] = (int) ($base[$key] ?? 0) + (int) ($next[$key] ?? 0);
        }
        $base['by_type'] = $this->mergeCounterMap($base['by_type'] ?? [], $next['by_type'] ?? []);
        $base['by_state'] = $this->mergeCounterMap($base['by_state'] ?? [], $next['by_state'] ?? []);
        $base['contexts'] = $this->mergeCounterMap($base['contexts'] ?? [], $next['contexts'] ?? []);
        return $base;
    }

    private function routeLatencyStats(): array
    {
        $stats = [];
        foreach ($this->httpRouteLatencySamples as $route => $sample) {
            $samples = $sample['values'] ?? [];
            sort($samples);
            $count = count($samples);
            $stats[$route] = [
                'p95' => $this->percentile($samples, 0.95),
                'p99' => $this->percentile($samples, 0.99),
                'max' => $count > 0 ? (float) $samples[$count - 1] : 0.0,
                'samples' => $count,
            ];
        }
        return $stats;
    }

    private function percentile(array $samples, float $percentile): float
    {
        $count = count($samples);
        if ($count === 0) {
            return 0.0;
        }
        $index = min($count - 1, max(0, (int) ceil($count * $percentile) - 1));
        return (float) $samples[$index];
    }

    private function writeBufferStats(): array
    {
        $bytes = 0;
        $max = 0;
        foreach ($this->connections as $conn) {
            $size = $conn->getWriteBufferSize();
            $bytes += $size;
            $max = max($max, $size);
        }
        return [
            'bytes' => $bytes,
            'max_bytes' => $max,
            'connections' => count($this->connections),
        ];
    }

    private function normalizeMetricPath(string $path): string
    {
        $path = preg_replace('#/\d+(?=/|$)#', '/{id}', $path) ?? $path;
        $path = preg_replace('#/[0-9a-fA-F-]{16,}(?=/|$)#', '/{id}', $path) ?? $path;
        return $path === '' ? '/' : $path;
    }

    private function aggregateMemoryTrend(array $workers): string
    {
        $trend = 'stable';
        foreach ($workers as $worker) {
            $workerTrend = $worker['memory']['trend'] ?? 'stable';
            if ($workerTrend === 'increasing') {
                return 'increasing';
            }
            if ($workerTrend === 'decreasing') {
                $trend = 'decreasing';
            }
        }
        return $trend;
    }

    private function countWebSockets(): int
    {
        $count = 0;
        foreach ($this->connections as $conn) {
            if ($conn->isWebSocket()) {
                $count++;
            }
        }
        return $count;
    }

    private function countSseConnections(): int
    {
        $count = 0;
        foreach ($this->connections as $conn) {
            if ($conn->isSse()) {
                $count++;
            }
        }
        return $count;
    }

    private function countSseReplayEvents(): int
    {
        $count = 0;
        foreach ($this->sseReplayBuffer as $channels) {
            foreach ($channels as $events) {
                $count += count($events);
            }
        }
        return $count;
    }

    private function getWebSocketRoomStats(): array
    {
        $rooms = 0;
        $memberships = 0;
        foreach ($this->webSocketRooms as $pathRooms) {
            foreach ($pathRooms as $members) {
                $rooms++;
                $memberships += count($members);
            }
        }
        return [
            'rooms' => $rooms,
            'memberships' => $memberships,
        ];
    }

    private function statsFile(): string
    {
        return $this->statsDir . '/worker-' . $this->workerId . '-' . getmypid() . '.json';
    }

    private function removeStatsFile(): void
    {
        @unlink($this->statsFile());
    }

    private function logStats(): void
    {
        $stats = $this->getStats();
        $this->log(sprintf(
            "Stats: %d requests, %d/%d connections, %.1fMB memory, %d coroutines",
            $stats['total_requests'],
            $stats['active_connections'],
            $stats['total_connections'],
            $stats['memory']['current'] / 1024 / 1024,
            $stats['coroutines']
        ));
    }

    private function log(string $message): void
    {
        ServerTUI::log($message);
    }

    public function getLoop(): EventLoop
    {
        return $this->loop;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    private function parseRequestLineFast(string $buffer): ?array
    {
        $s1 = strpos($buffer, ' ');
        if ($s1 === false) {
            return null;
        }
        $s2 = strpos($buffer, ' ', $s1 + 1);
        if ($s2 === false) {
            return null;
        }
        $method = substr($buffer, 0, $s1);
        $uri = substr($buffer, $s1 + 1, $s2 - $s1 - 1);
        $qPos = strpos($uri, '?');
        $path = $qPos !== false ? substr($uri, 0, $qPos) : $uri;
        return [$method, $path];
    }

    public function fastJson(string $method, string $path, array|string $payload, int $status = 200, array $headers = []): self
    {
        $raw = RawResponseBuilder::json($status, $payload, $headers);
        $this->fastPath->register($method, $path, $raw);
        $this->fastEngine->register($method, $path, $raw);
        return $this;
    }

    public function fastText(string $method, string $path, string $body, int $status = 200, array $headers = []): self
    {
        $raw = RawResponseBuilder::text($status, $body, $headers);
        $this->fastPath->register($method, $path, $raw);
        $this->fastEngine->register($method, $path, $raw);
        return $this;
    }

    public function fastRaw(string $method, string $path, string $rawResponse): self
    {
        $this->fastPath->register($method, $path, $rawResponse);
        $this->fastEngine->register($method, $path, $rawResponse);
        return $this;
    }

    public function fastCachedJson(string $method, string $path, callable $handler, int $ttl = 1): self
    {
        $cacheKey = "fastpath:{$method}:{$path}";
        $cached = apcu_fetch($cacheKey, $success);
        if ($success) {
            $this->fastPath->register($method, $path, $cached);
            $this->fastEngine->register($method, $path, $cached);
        } else {
            $data = $handler();
            $raw = RawResponseBuilder::json(200, $data);
            apcu_store($cacheKey, $raw, $ttl);
            $this->fastPath->register($method, $path, $raw);
            $this->fastEngine->register($method, $path, $raw);
        }
        return $this;
    }

    public function fastCompressedJson(string $method, string $path, array|string $payload, int $status = 200, array $headers = []): self
    {
        if (!extension_loaded('zlib')) {
            return $this->fastJson($method, $path, $payload, $status, $headers);
        }

        $json = is_string($payload) ? $payload : json_encode($payload);
        $compressed = gzencode($json, 6);

        $headers['Content-Encoding'] = 'gzip';
        $headers['Vary'] = 'Accept-Encoding';

        $raw = RawResponseBuilder::raw($status, $compressed, $headers);
        $this->fastPath->register($method, $path, $raw);
        $this->fastEngine->register($method, $path, $raw);
        return $this;
    }
}
