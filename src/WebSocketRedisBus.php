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

class WebSocketRedisBus
{
    private string $host = '127.0.0.1';
    private int $port = 6379;
    private ?string $password = null;
    private int $database = 0;
    private string $channel = 'nexph:websocket';
    private $publisher = null;
    private $subscriber = null;
    private string $buffer = '';
    private int $published = 0;
    private int $received = 0;
    private int $errors = 0;

    public function __construct(string $url = 'redis://127.0.0.1:6379/0', string $channel = 'nexph:websocket')
    {
        $parts = parse_url($url) ?: [];
        $this->host = (string) ($parts['host'] ?? $this->host);
        $this->port = (int) ($parts['port'] ?? $this->port);
        $this->password = isset($parts['pass']) ? (string) $parts['pass'] : null;
        $this->database = max(0, (int) ltrim((string) ($parts['path'] ?? '/0'), '/'));
        $this->channel = $channel;
    }

    public function start(EventLoop $loop, callable $onEvent): void
    {
        $this->subscriber = $this->connect();
        if (!$this->subscriber) {
            return;
        }

        stream_set_blocking($this->subscriber, false);
        $this->writeCommand($this->subscriber, ['SUBSCRIBE', $this->channel]);
        $loop->addReader($this->subscriber, function () use ($onEvent) {
            $this->read($onEvent);
        });
    }

    public function publish(string $payload): void
    {
        if (!$this->publisher || !is_resource($this->publisher)) {
            $this->publisher = $this->connect();
        }

        if (!$this->publisher || !$this->writeCommand($this->publisher, ['PUBLISH', $this->channel, $payload])) {
            $this->errors++;
            $this->publisher = null;
            return;
        }

        $this->published++;
    }

    public function stats(): array
    {
        return [
            'type' => 'redis',
            'channel' => $this->channel,
            'published' => $this->published,
            'received' => $this->received,
            'errors' => $this->errors,
            'connected' => is_resource($this->subscriber),
        ];
    }

    public function close(): void
    {
        foreach ([$this->publisher, $this->subscriber] as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
        $this->publisher = null;
        $this->subscriber = null;
    }

    private function connect()
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 1.0);
        if (!$socket) {
            $this->errors++;
            return null;
        }

        stream_set_timeout($socket, 1);
        if ($this->password !== null && !$this->commandOk($socket, ['AUTH', $this->password])) {
            @fclose($socket);
            $this->errors++;
            return null;
        }
        if ($this->database > 0 && !$this->commandOk($socket, ['SELECT', (string) $this->database])) {
            @fclose($socket);
            $this->errors++;
            return null;
        }

        return $socket;
    }

    private function commandOk($socket, array $parts): bool
    {
        if (!$this->writeCommand($socket, $parts)) {
            return false;
        }
        $line = fgets($socket);
        return is_string($line) && ($line[0] ?? '') === '+';
    }

    private function writeCommand($socket, array $parts): bool
    {
        $command = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $part = (string) $part;
            $command .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }
        return @fwrite($socket, $command) !== false;
    }

    private function read(callable $onEvent): void
    {
        if (!is_resource($this->subscriber)) {
            return;
        }

        $chunk = @fread($this->subscriber, 65536);
        if ($chunk === false || $chunk === '') {
            return;
        }

        $this->buffer .= $chunk;
        while (($message = $this->readRespArray($this->buffer)) !== null) {
            if (($message[0] ?? '') !== 'message' || ($message[1] ?? '') !== $this->channel) {
                continue;
            }
            $this->received++;
            $onEvent((string) ($message[2] ?? ''));
        }
    }

    private function readRespArray(string &$buffer): ?array
    {
        if ($buffer === '' || $buffer[0] !== '*') {
            return null;
        }

        $lineEnd = strpos($buffer, "\r\n");
        if ($lineEnd === false) {
            return null;
        }

        $count = (int) substr($buffer, 1, $lineEnd - 1);
        $offset = $lineEnd + 2;
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            if (!isset($buffer[$offset]) || $buffer[$offset] !== '$') {
                return null;
            }
            $lineEnd = strpos($buffer, "\r\n", $offset);
            if ($lineEnd === false) {
                return null;
            }
            $length = (int) substr($buffer, $offset + 1, $lineEnd - $offset - 1);
            $offset = $lineEnd + 2;
            if (strlen($buffer) < $offset + $length + 2) {
                return null;
            }
            $items[] = substr($buffer, $offset, $length);
            $offset += $length + 2;
        }

        $buffer = substr($buffer, $offset);
        return $items;
    }
}
