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
 * Immutable Cookie value object.
 *
 * Build with the fluent factory methods; every mutating method
 * returns a new instance.
 */
final class Cookie
{
    private function __construct(
        private readonly string $name,
        private readonly string $value,
        private readonly int    $maxAge,
        private readonly int    $expires,
        private readonly string $path,
        private readonly string $domain,
        private readonly bool   $secure,
        private readonly bool   $httpOnly,
        private readonly string $sameSite,
    ) {}

    // -------------------------------------------------------------------------
    // Factories
    // -------------------------------------------------------------------------

    public static function make(string $name, string $value, int $maxAge = 0): self
    {
        self::assertValidName($name);
        return new self(
            name:     $name,
            value:    $value,
            maxAge:   $maxAge,
            expires:  $maxAge > 0 ? time() + $maxAge : 0,
            path:     '/',
            domain:   '',
            secure:   true,
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    /** Cookie that never expires (10 years). */
    public static function forever(string $name, string $value): self
    {
        return self::make($name, $value, 315_360_000);
    }

    /** Expiry-in-the-past cookie — instructs browser to delete it. */
    public static function forget(string $name): self
    {
        return new self(
            name:     $name,
            value:    '',
            maxAge:   0,
            expires:  1,
            path:     '/',
            domain:   '',
            secure:   true,
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    // -------------------------------------------------------------------------
    // Immutable setters
    // -------------------------------------------------------------------------

    public function withValue(string $value): self
    {
        return new self($this->name, $value, $this->maxAge, $this->expires,
            $this->path, $this->domain, $this->secure, $this->httpOnly, $this->sameSite);
    }

    public function withMaxAge(int $seconds): self
    {
        return new self($this->name, $this->value, $seconds,
            $seconds > 0 ? time() + $seconds : 0,
            $this->path, $this->domain, $this->secure, $this->httpOnly, $this->sameSite);
    }

    public function withExpires(int $timestamp): self
    {
        return new self($this->name, $this->value, $this->maxAge, $timestamp,
            $this->path, $this->domain, $this->secure, $this->httpOnly, $this->sameSite);
    }

    public function withPath(string $path): self
    {
        return new self($this->name, $this->value, $this->maxAge, $this->expires,
            $path, $this->domain, $this->secure, $this->httpOnly, $this->sameSite);
    }

    public function withDomain(string $domain): self
    {
        return new self($this->name, $this->value, $this->maxAge, $this->expires,
            $this->path, $domain, $this->secure, $this->httpOnly, $this->sameSite);
    }

    public function withSecure(bool $secure = true): self
    {
        return new self($this->name, $this->value, $this->maxAge, $this->expires,
            $this->path, $this->domain, $secure, $this->httpOnly, $this->sameSite);
    }

    public function withHttpOnly(bool $httpOnly = true): self
    {
        return new self($this->name, $this->value, $this->maxAge, $this->expires,
            $this->path, $this->domain, $this->secure, $httpOnly, $this->sameSite);
    }

    public function withSameSite(string $sameSite): self
    {
        $sameSite = ucfirst(strtolower($sameSite));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException("Invalid SameSite value: {$sameSite}");
        }
        return new self($this->name, $this->value, $this->maxAge, $this->expires,
            $this->path, $this->domain, $this->secure, $this->httpOnly, $sameSite);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getName(): string    { return $this->name; }
    public function getValue(): string   { return $this->value; }
    public function getMaxAge(): int     { return $this->maxAge; }
    public function getExpires(): int    { return $this->expires; }
    public function getPath(): string    { return $this->path; }
    public function getDomain(): string  { return $this->domain; }
    public function isSecure(): bool     { return $this->secure; }
    public function isHttpOnly(): bool   { return $this->httpOnly; }
    public function getSameSite(): string { return $this->sameSite; }

    // -------------------------------------------------------------------------
    // Serialisation
    // -------------------------------------------------------------------------

    /** Render as a Set-Cookie header value string. */
    public function toHeaderValue(): string
    {
        $parts = [urlencode($this->name) . '=' . urlencode($this->value)];

        if ($this->expires > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $this->expires);
        }
        if ($this->maxAge !== 0) {
            $parts[] = 'Max-Age=' . $this->maxAge;
        }
        if ($this->path !== '') {
            $parts[] = 'Path=' . $this->path;
        }
        if ($this->domain !== '') {
            $parts[] = 'Domain=' . $this->domain;
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if ($this->sameSite !== '') {
            $parts[] = 'SameSite=' . $this->sameSite;
        }

        return implode('; ', $parts);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function assertValidName(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid cookie name: {$name}");
        }
    }
}
