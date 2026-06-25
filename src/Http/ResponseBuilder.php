<?php

namespace Nexphant\Server\Http;

use Nexphant\Server\ServerResponse;

/**
 * ResponseBuilder — fluent immutable response factory.
 *
 * Usage:
 *   response()->json(['ok' => true])
 *   response()->status(201)->header('X-Id', '1')->json($data)
 *   response()->redirect('/login')
 *   response()->html('<h1>Hello</h1>')
 *   response()->text('plain text')
 *   response()->download($path, 'file.pdf')
 */
final class ResponseBuilder
{
    private int    $status  = 200;
    private array  $headers = [];
    private array  $cookies = [];

    // ── Fluent setters (return new instance — immutable) ─────────────────────

    public function status(int $code): self
    {
        $clone = clone $this;
        $clone->status = $code;
        return $clone;
    }

    public function header(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function cookie(string $name, string $value, array $options = []): self
    {
        $clone = clone $this;
        $clone->cookies[] = compact('name', 'value', 'options');
        return $clone;
    }

    // ── Terminal methods — produce ServerResponse ─────────────────────────────

    public function json(mixed $data, int $status = 0): ServerResponse
    {
        $res = $this->applyTo(new ServerResponse());
        return $res->json($data, $status ?: $this->status);
    }

    public function html(string $html, int $status = 0): ServerResponse
    {
        $res = $this->applyTo(new ServerResponse());
        return $res->html($html, $status ?: $this->status);
    }

    public function text(string $text, int $status = 0): ServerResponse
    {
        $res = $this->applyTo(new ServerResponse());
        return $res->text($text, $status ?: $this->status);
    }

    public function redirect(string $url, int $status = 302): ServerResponse
    {
        $res = $this->applyTo(new ServerResponse());
        return $res->redirect($url, $status);
    }

    public function back(string $fallback = '/'): ServerResponse
    {
        global $__nx_request;
        $referer = $__nx_request?->header('Referer') ?? $fallback;
        return $this->redirect($referer ?: $fallback);
    }

    public function noContent(): ServerResponse
    {
        $res = $this->applyTo(new ServerResponse());
        return $res->status(204)->body('');
    }

    private function applyTo(ServerResponse $res): ServerResponse
    {
        $res->status($this->status);
        foreach ($this->headers as $k => $v) {
            $res->header($k, $v);
        }
        foreach ($this->cookies as $c) {
            $res->cookie($c['name'], $c['value'], $c['options']);
        }
        return $res;
    }
}
