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

class EventLoop
{
    private array $readers = [];
    private array $writers = [];
    private array $readerStreams = [];
    private array $writerStreams = [];
    private bool $streamsDirty = true;
    private array $timers = [];
    private \SplPriorityQueue $timerQueue;
    private int $timerId = 0;
    private array $deferred = [];
    private int $deferredHead = 0;
    private int $deferredCount = 0;
    private bool $processingDeferred = false;
    private array $signals = [];
    private bool $running = false;
    private int $tickInterval = 1000;
    private int $maxDeferred = 100000;
    private int $deferredDropped = 0;
    private float $now;
    private int $tickCount = 0;
    private float $lastTickDurationMs = 0.0;
    private ?\Nexphant\Runtime\EventLoop\EventLoopInterface $backend = null;
    private ?int $deferredTimerId = null;
    private int $maxReadCallbacksPerTick = 64;
    private int $maxWriteCallbacksPerTick = 64;
    private int $maxDeferredPerTick = 256;

    public function __construct(?\Nexphant\Runtime\EventLoop\EventLoopInterface $backend = null)
    {
        $this->backend = $backend;
        $this->now = microtime(true);
        $this->timerQueue = new \SplPriorityQueue();
        $this->timerQueue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
    }

    public function addReader($stream, callable $callback): void
    {
        if ($this->backend) {
            $this->backend->onReadable($stream, $callback);
            return;
        }
        $id = (int) $stream;
        $this->readers[$id] = ['stream' => $stream, 'callback' => $callback];
        $this->streamsDirty = true;
    }

    public function removeReader($stream): void
    {
        if ($this->backend) {
            $this->backend->removeReadable($stream);
            return;
        }
        unset($this->readers[(int) $stream]);
        $this->streamsDirty = true;
    }

    public function addWriter($stream, callable $callback): void
    {
        if ($this->backend) {
            $this->backend->onWritable($stream, $callback);
            return;
        }
        $id = (int) $stream;
        $this->writers[$id] = ['stream' => $stream, 'callback' => $callback];
        $this->streamsDirty = true;
    }

    public function removeWriter($stream): void
    {
        if ($this->backend) {
            $this->backend->removeWritable($stream);
            return;
        }
        unset($this->writers[(int) $stream]);
        $this->streamsDirty = true;
    }

    public function addTimer(float $interval, callable $callback, bool $periodic = false): int
    {
        if ($this->backend) {
            return $this->backend->timer($interval, $callback, $periodic);
        }
        $id = ++$this->timerId;
        $next = microtime(true) + $interval;
        $this->timers[$id] = [
            'interval' => $interval,
            'callback' => $callback,
            'periodic' => $periodic,
            'next' => $next,
        ];
        $this->timerQueue->insert($id, -$next);
        return $id;
    }

    public function cancelTimer(int $id): void
    {
        if ($this->backend) {
            $this->backend->cancelTimer($id);
            return;
        }
        unset($this->timers[$id]);
    }

    public function setMaxDeferred(int $maxDeferred): void
    {
        $this->maxDeferred = max(1, $maxDeferred);
    }

    public function setFairnessLimits(int $maxRead, int $maxWrite, int $maxDeferred): void
    {
        $this->maxReadCallbacksPerTick = max(1, $maxRead);
        $this->maxWriteCallbacksPerTick = max(1, $maxWrite);
        $this->maxDeferredPerTick = max(1, $maxDeferred);
    }

    public function defer(callable $callback): bool
    {
        if ($this->deferredCount >= $this->maxDeferred) {
            $this->deferredDropped++;
            return false;
        }

        $this->deferred[] = $callback;
        $this->deferredCount++;

        if ($this->backend && $this->deferredTimerId === null && $this->running) {
            $this->deferredTimerId = $this->backend->timer(0.001, function () {
                $this->processDeferredQueue();
            }, true);
        }

        return true;
    }

    public function addSignal(int $signal, callable $callback): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal($signal, function ($sig) use ($callback) {
                $this->defer(fn() => $callback($sig));
            });
            $this->signals[$signal] = $callback;
        }
    }

    public function run(): void
    {
        if ($this->backend) {
            $this->running = true;
            $this->backend->run();
            $this->running = false;
            return;
        }

        $this->running = true;

        while ($this->running) {
            $this->tick();
        }
    }

    public function stop(): void
    {
        if ($this->backend) {
            $this->backend->stop();
        }
        $this->running = false;
    }

    private function processDeferredQueue(): void
    {
        if ($this->processingDeferred || $this->deferredCount === 0) {
            if (!$this->processingDeferred && $this->backend && $this->deferredTimerId !== null) {
                $this->backend->cancelTimer($this->deferredTimerId);
                $this->deferredTimerId = null;
            }
            return;
        }

        $this->processingDeferred = true;
        try {
            $deferredEnd = $this->deferredHead + min($this->deferredCount, $this->maxDeferredPerTick);
            $processed = 0;
            for ($i = $this->deferredHead; $i < $deferredEnd; $i++) {
                $cb = $this->deferred[$i] ?? null;
                unset($this->deferred[$i]);
                $processed++;
                if ($cb !== null) {
                    $cb();
                }
            }
            $this->deferredHead += $processed;
            $this->deferredCount -= $processed;

            if ($this->deferredCount === 0) {
                if ($this->backend && $this->deferredTimerId !== null) {
                    $this->backend->cancelTimer($this->deferredTimerId);
                    $this->deferredTimerId = null;
                }
            }

            if ($this->deferredHead > 64 && $this->deferredHead * 2 >= count($this->deferred) + $this->deferredHead) {
                $this->deferred = array_values(array_slice($this->deferred, $this->deferredHead, null, true));
                $this->deferredHead = 0;
            }
        } finally {
            $this->processingDeferred = false;
        }
    }

    public function tick(): void
    {
        $this->tickCount++;
        $this->now = microtime(true);

        if ($this->deferredCount > 0) {
            $this->processDeferredQueue();
        }

        $this->processTimers();

        // Prepare streams (cached)
        if ($this->streamsDirty) {
            $this->readerStreams = array_column($this->readers, 'stream');
            $this->writerStreams = array_column($this->writers, 'stream');
            $this->streamsDirty = false;
        }
        $read = $this->readerStreams;
        $write = $this->writerStreams;
        $except = null;

        if (empty($read) && empty($write)) {
            if (empty($this->timers) && $this->deferredCount === 0) {
                $this->running = false;
                return;
            }
            usleep($this->tickInterval);
            return;
        }

        // Calculate timeout
        $timeout = $this->calculateTimeout();
        $tvSec = (int) $timeout;
        $tvUsec = (int) (($timeout - $tvSec) * 1000000);

        $result = @stream_select($read, $write, $except, $tvSec, $tvUsec);

        if ($result === false) {
            $this->cleanupInvalidStreams();
            return;
        }

        // Handle readable
        $readCount = 0;
        foreach ($read as $stream) {
            if ($readCount++ >= $this->maxReadCallbacksPerTick)
                break;
            $id = (int) $stream;
            if (isset($this->readers[$id])) {
                ($this->readers[$id]['callback'])($stream);
            }
        }

        // Handle writable
        $writeCount = 0;
        foreach ($write as $stream) {
            if ($writeCount++ >= $this->maxWriteCallbacksPerTick)
                break;
            $id = (int) $stream;
            if (isset($this->writers[$id])) {
                ($this->writers[$id]['callback'])($stream);
            }
        }
    }

    private function calculateTimeout(): float
    {
        if ($this->deferredCount > 0) {
            return 0;
        }

        $next = $this->nextTimerAt();
        if ($next === null) {
            return 1.0;
        }

        return min(1.0, max(0.0, $next - $this->now));
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function now(): float
    {
        return $this->now;
    }

    private function cleanupInvalidStreams(): void
    {
        foreach ($this->readers as $id => $entry) {
            if (!is_resource($entry['stream'])) {
                unset($this->readers[$id]);
            }
        }
        foreach ($this->writers as $id => $entry) {
            if (!is_resource($entry['stream'])) {
                unset($this->writers[$id]);
            }
        }
    }

    public function getReaderCount(): int
    {
        return count($this->readers);
    }

    public function getWriterCount(): int
    {
        return count($this->writers);
    }

    public function getTimerCount(): int
    {
        return count($this->timers);
    }

    public function getDeferredCount(): int
    {
        return $this->deferredCount;
    }

    public function getDeferredDroppedCount(): int
    {
        return $this->deferredDropped;
    }

    public function getMaxDeferred(): int
    {
        return $this->maxDeferred;
    }

    public function getTickCount(): int
    {
        return $this->tickCount;
    }

    public function getLastTickDurationMs(): float
    {
        return $this->lastTickDurationMs;
    }

    private function processTimers(): void
    {
        while (($next = $this->nextTimerAt()) !== null && $next <= $this->now) {
            $id = $this->timerQueue->extract();
            if (!isset($this->timers[$id])) {
                continue;
            }

            $timer = $this->timers[$id];
            if ($timer['next'] > $this->now) {
                $this->timerQueue->insert($id, -$timer['next']);
                break;
            }

            ($timer['callback'])();
            if (!isset($this->timers[$id])) {
                continue;
            }
            if ($timer['periodic']) {
                $next = $this->now + $timer['interval'];
                $this->timers[$id]['next'] = $next;
                $this->timerQueue->insert($id, -$next);
            } else {
                unset($this->timers[$id]);
            }
        }
    }

    private function nextTimerAt(): ?float
    {
        while (!$this->timerQueue->isEmpty()) {
            $id = $this->timerQueue->current();
            if (!isset($this->timers[$id])) {
                $this->timerQueue->extract();
                continue;
            }
            return (float) $this->timers[$id]['next'];
        }
        return null;
    }
}
