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

class Coroutine
{
    private static array $tasks = [];
    private static int $taskId = 0;
    private static ?EventLoop $loop = null;

    public static function setLoop(EventLoop $loop): void
    {
        self::$loop = $loop;
    }

    public static function create(\Generator $generator): int
    {
        $task = new Task(++self::$taskId, $generator);
        self::$tasks[$task->getId()] = $task;
        self::schedule($task);
        return $task->getId();
    }

    public static function schedule(Task $task): void
    {
        self::$loop?->defer(function () use ($task) {
            self::runTask($task);
        });
    }

    private static function runTask(Task $task): void
    {
        try {
            if (!$task->isFinished()) {
                $value = $task->run();

                if ($value instanceof Awaitable) {
                    try {
                        $value->then(function ($result) use ($task) {
                            $task->send($result);
                            self::schedule($task);
                        });
                    } catch (\Throwable $e) {
                        error_log('Awaitable then() error: ' . $e->getMessage());
                    }
                } elseif ($value instanceof \Generator) {
                    self::create($value);
                    self::schedule($task);
                } elseif (!$task->isFinished()) {
                    self::schedule($task);
                }
            }
        } catch (\Throwable $e) {
            error_log('Coroutine error: ' . $e->getMessage());
        } finally {
            try {
                if ($task->isFinished()) {
                    unset(self::$tasks[$task->getId()]);
                }
            } catch (\Throwable $e) {
                unset(self::$tasks[$task->getId()]);
            }
        }
    }

    public static function async(callable $fn): \Generator
    {
        yield from $fn();
    }

    public static function await(Awaitable $awaitable): \Generator
    {
        return yield $awaitable;
    }

    public static function sleep(float $seconds): Awaitable
    {
        return new Timer($seconds, self::$loop);
    }

    public static function all(array $awaitables): Awaitable
    {
        return new AwaitAll($awaitables, self::$loop);
    }

    public static function count(): int
    {
        return count(self::$tasks);
    }
}

class Task
{
    private int $id;
    private \Generator $coroutine;
    private mixed $sendValue = null;
    private bool $started = false;

    public function __construct(int $id, \Generator $coroutine)
    {
        $this->id = $id;
        $this->coroutine = $coroutine;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function run(): mixed
    {
        if (!$this->started) {
            $this->started = true;
            return $this->coroutine->current();
        }
        $result = $this->coroutine->send($this->sendValue);
        $this->sendValue = null;
        return $result;
    }

    public function send(mixed $value): void
    {
        $this->sendValue = $value;
    }

    public function isFinished(): bool
    {
        return !$this->coroutine->valid();
    }

    public function getReturn(): mixed
    {
        return $this->coroutine->getReturn();
    }
}

interface Awaitable
{
    public function then(callable $callback): void;
}

class Timer implements Awaitable
{
    private float $delay;
    private ?EventLoop $loop;
    /** @var callable|null */
    private $callback = null;

    public function __construct(float $delay, ?EventLoop $loop)
    {
        $this->delay = $delay;
        $this->loop = $loop;
    }

    public function then(callable $callback): void
    {
        $this->callback = $callback;
        $this->loop?->addTimer($this->delay, function () {
            ($this->callback)(null);
        });
    }
}

class Deferred implements Awaitable
{
    /** @var callable|null */
    private $callback = null;
    private bool $resolved = false;
    private mixed $value = null;

    public function resolve(mixed $value = null): void
    {
        if ($this->resolved)
            return;
        $this->resolved = true;
        $this->value = $value;
        if ($this->callback) {
            $cb = $this->callback;
            $this->callback = null;
            $cb($value);
        }
    }

    public function then(callable $callback): void
    {
        if ($this->resolved) {
            $callback($this->value);
            return;
        }
        $this->callback = $callback;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}

class AwaitAll implements Awaitable
{
    private array $awaitables;
    private array $results = [];
    private int $pending;
    /** @var callable|null */
    private $callback = null;

    public function __construct(array $awaitables, ?EventLoop $loop)
    {
        $this->awaitables = $awaitables;
        $this->pending = count($awaitables);
    }

    public function then(callable $callback): void
    {
        $this->callback = $callback;

        if ($this->pending === 0) {
            $callback([]);
            return;
        }

        foreach ($this->awaitables as $i => $awaitable) {
            $awaitable->then(function ($result) use ($i) {
                $this->results[$i] = $result;
                $this->pending--;
                if ($this->pending === 0 && $this->callback) {
                    ($this->callback)($this->results);
                }
            });
        }
    }
}
