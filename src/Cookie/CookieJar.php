<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Cookie;

/**
 * Queues outgoing cookies and applies them to a response.
 *
 * Supports signed and encrypted cookies when a secret / key is configured.
 */
class CookieJar
{
    /** @var Cookie[] */
    private array $queue = [];

    private string $secret;
    private bool   $encrypt;

    public function __construct(string $secret = '', bool $encrypt = false)
    {
        $this->secret  = $secret;
        $this->encrypt = $encrypt && $secret !== '' && function_exists('openssl_encrypt');
    }

    // -------------------------------------------------------------------------
    // Queuing
    // -------------------------------------------------------------------------

    public function queue(Cookie $cookie): void
    {
        $this->queue[$cookie->getName()] = $this->seal($cookie);
    }

    public function make(string $name, string $value, int $maxAge = 0): Cookie
    {
        $cookie = Cookie::make($name, $value, $maxAge);
        $this->queue($cookie);
        return $cookie;
    }

    public function forever(string $name, string $value): Cookie
    {
        $cookie = Cookie::forever($name, $value);
        $this->queue($cookie);
        return $cookie;
    }

    public function forget(string $name): Cookie
    {
        $cookie = Cookie::forget($name);
        $this->queue[$name] = $cookie;
        return $cookie;
    }

    /** @return Cookie[] */
    public function queued(): array
    {
        return array_values($this->queue);
    }

    public function hasQueued(string $name): bool
    {
        return isset($this->queue[$name]);
    }

    public function dequeue(string $name): void
    {
        unset($this->queue[$name]);
    }

    public function flush(): void
    {
        $this->queue = [];
    }

    // -------------------------------------------------------------------------
    // Reading (signed / encrypted)
    // -------------------------------------------------------------------------

    /**
     * Verify and return the cookie value from a raw cookie string.
     * Returns null if the cookie is missing or signature/decryption fails.
     */
    public function get(string $name, string $cookieHeader): ?string
    {
        $raw = $this->extractCookieValue($cookieHeader, $name);
        if ($raw === null) {
            return null;
        }
        return $this->unseal($raw);
    }

    // -------------------------------------------------------------------------
    // Signing & Encryption
    // -------------------------------------------------------------------------

    private function seal(Cookie $cookie): Cookie
    {
        if ($this->secret === '') {
            return $cookie;
        }
        $value = $this->encrypt
            ? $this->encryptValue($cookie->getValue())
            : $this->signValue($cookie->getValue());

        return $cookie->withValue($value);
    }

    private function unseal(string $raw): ?string
    {
        if ($this->secret === '') {
            return $raw;
        }
        return $this->encrypt
            ? $this->decryptValue($raw)
            : $this->verifySignedValue($raw);
    }

    private function signValue(string $value): string
    {
        $sig = hash_hmac('sha256', $value, $this->secret);
        return base64_encode($value . '|' . $sig);
    }

    private function verifySignedValue(string $raw): ?string
    {
        $decoded = base64_decode($raw, strict: true);
        if ($decoded === false) {
            return null;
        }
        $pos = strrpos($decoded, '|');
        if ($pos === false) {
            return null;
        }
        $value = substr($decoded, 0, $pos);
        $sig   = substr($decoded, $pos + 1);
        $expected = hash_hmac('sha256', $value, $this->secret);
        return hash_equals($expected, $sig) ? $value : null;
    }

    private function encryptValue(string $value): string
    {
        $iv  = random_bytes(16);
        $key = hash('sha256', $this->secret, true);
        $enc = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $mac = hash_hmac('sha256', $enc, $key, true);
        return base64_encode($iv . $mac . $enc);
    }

    private function decryptValue(string $raw): ?string
    {
        $decoded = base64_decode($raw, strict: true);
        if ($decoded === false || strlen($decoded) < 48) {
            return null;
        }
        $key = hash('sha256', $this->secret, true);
        $iv  = substr($decoded, 0, 16);
        $mac = substr($decoded, 16, 32);
        $enc = substr($decoded, 48);
        $expected = hash_hmac('sha256', $enc, $key, true);
        if (!hash_equals($expected, $mac)) {
            return null;
        }
        $dec = openssl_decrypt($enc, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $dec === false ? null : $dec;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function extractCookieValue(string $header, string $name): ?string
    {
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            $eq   = strpos($part, '=');
            if ($eq === false) continue;
            if (trim(substr($part, 0, $eq)) === $name) {
                return urldecode(trim(substr($part, $eq + 1)));
            }
        }
        return null;
    }
}
