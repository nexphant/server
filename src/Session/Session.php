<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Session;

/**
 * Secure session implementation.
 *
 * Security features:
 * - Cryptographically random session IDs (32 bytes / 64 hex chars)
 * - HMAC-SHA256 signature on session cookie to prevent tampering
 * - Session ID regeneration to prevent fixation
 * - Idle timeout and absolute lifetime enforcement
 * - IP/UA binding (optional, configurable)
 */
class Session implements SessionInterface
{
    private string $id = '';
    private array $data = [];
    private bool $started = false;
    private bool $modified = false;

    private SessionStorageInterface $storage;

    private string $cookieName;
    private int $ttl;
    private int $absoluteTtl;
    private string $hmacSecret;
    private bool $bindIp;
    private bool $bindUserAgent;
    private string $currentIp;
    private string $currentUa;

    public function __construct(SessionStorageInterface $storage, array $options = [])
    {
        $this->storage      = $storage;
        $this->cookieName   = $options['name']          ?? 'nxsess';
        $this->ttl          = (int) ($options['ttl']    ?? 7200);
        $this->absoluteTtl  = (int) ($options['absolute_ttl'] ?? 86400);
        $this->hmacSecret   = $options['secret']        ?? '';
        $this->bindIp       = (bool) ($options['bind_ip']       ?? false);
        $this->bindUserAgent = (bool) ($options['bind_ua']      ?? true);
        $this->currentIp    = $options['ip']            ?? '';
        $this->currentUa    = $options['ua']            ?? '';

        if ($this->hmacSecret === '') {
            throw new \InvalidArgumentException('Session HMAC secret must not be empty');
        }
    }

    /** Load session from a raw cookie string (request Cookie header). */
    public function start(string $cookieHeader): void
    {
        if ($this->started) {
            return;
        }

        $rawId = $this->extractCookieValue($cookieHeader, $this->cookieName);

        if ($rawId !== null) {
            $id = $this->verifySignedId($rawId);
            if ($id !== null) {
                $data = $this->storage->read($id);
                if ($this->validateSessionData($data)) {
                    $this->id   = $id;
                    $this->data = $data;
                    // Update last-active for idle timeout
                    $this->data['_meta']['last_active'] = time();
                    $this->modified = true;
                    $this->started  = true;
                    return;
                }
            }
        }

        // New session
        $this->id      = $this->generateId();
        $this->data    = $this->buildMeta();
        $this->modified = true;
        $this->started  = true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (str_starts_with($key, '_meta')) {
            throw new \InvalidArgumentException('Key "_meta" is reserved');
        }
        $this->data[$key] = $value;
        $this->modified   = true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && !str_starts_with($key, '_meta');
    }

    public function put(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
        $this->modified = true;
    }

    public function forget(string $key): void
    {
        $this->remove($key);
    }

    public function invalidate(): string
    {
        $this->clear();
        return $this->regenerate();
    }

    // -------------------------------------------------------------------------
    // Flash Session
    // -------------------------------------------------------------------------

    public function flash(string $key, mixed $value): void
    {
        $flash = $this->data['_flash_new'] ?? [];
        $flash[$key] = $value;
        $this->data['_flash_new'] = $flash;
        $this->modified = true;
    }

    public function now(string $key, mixed $value): void
    {
        $flash = $this->data['_flash_now'] ?? [];
        $flash[$key] = $value;
        $this->data['_flash_now'] = $flash;
        $this->modified = true;
    }

    public function reflash(): void
    {
        $current = $this->data['_flash_old'] ?? [];
        $new     = $this->data['_flash_new'] ?? [];
        $this->data['_flash_new'] = array_merge($new, $current);
        $this->modified = true;
    }

    public function keep(string ...$keys): void
    {
        $old = $this->data['_flash_old'] ?? [];
        $new = $this->data['_flash_new'] ?? [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $old)) {
                $new[$key] = $old[$key];
            }
        }
        $this->data['_flash_new'] = $new;
        $this->modified = true;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return ($this->data['_flash_now'] ?? [])[$key]
            ?? ($this->data['_flash_old'] ?? [])[$key]
            ?? $default;
    }

    public function hasFlash(string $key): bool
    {
        return array_key_exists($key, $this->data['_flash_now'] ?? [])
            || array_key_exists($key, $this->data['_flash_old'] ?? []);
    }

    /**
     * Age flash data: promote _flash_new → _flash_old, clear _flash_now.
     * Call this once per request, typically in SessionMiddleware after next().
     */
    public function ageFlash(): void
    {
        $this->data['_flash_old'] = $this->data['_flash_new'] ?? [];
        $this->data['_flash_new'] = [];
        $this->data['_flash_now'] = [];
        $this->modified = true;
    }

    // -------------------------------------------------------------------------
    // Old Input
    // -------------------------------------------------------------------------

    public function withInput(array $input): void
    {
        $this->data['_old_input'] = $input;
        $this->modified = true;
    }

    public function old(string $key, mixed $default = null): mixed
    {
        return ($this->data['_old_input'] ?? [])[$key] ?? $default;
    }

    public function all(): array
    {
        return array_filter(
            $this->data,
            static fn(string $k) => !str_starts_with($k, '_meta'),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function clear(): void
    {
        $meta        = $this->data['_meta'] ?? $this->buildMeta()['_meta'];
        $this->data  = ['_meta' => $meta];
        $this->modified = true;
    }

    public function save(): void
    {
        if ($this->modified && $this->started) {
            $this->storage->write($this->id, $this->data, $this->ttl);
            $this->modified = false;
        }
    }

    public function destroy(): void
    {
        $this->storage->destroy($this->id);
        $this->id      = '';
        $this->data    = [];
        $this->started = false;
        $this->modified = false;
    }

    /**
     * Issue a new session ID, copy data, destroy old session.
     * Returns the new signed cookie value.
     */
    public function regenerate(): string
    {
        $oldId = $this->id;
        $this->id = $this->generateId();
        $this->data['_meta']['created_at'] = time(); // reset absolute TTL on regen
        $this->data['_meta']['last_active'] = time();
        $this->modified = true;
        $this->save();
        $this->storage->destroy($oldId);
        return $this->signedId();
    }

    /** Return the signed cookie value to set on the response. */
    public function cookieValue(): string
    {
        return $this->signedId();
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function signedId(): string
    {
        $sig = hash_hmac('sha256', $this->id, $this->hmacSecret);
        return $this->id . '.' . $sig;
    }

    private function verifySignedId(string $raw): ?string
    {
        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$id, $sig] = $parts;

        // Validate ID format (64 hex chars)
        if (!preg_match('/^[0-9a-f]{64}$/', $id)) {
            return null;
        }

        $expected = hash_hmac('sha256', $id, $this->hmacSecret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return $id;
    }

    private function validateSessionData(array $data): bool
    {
        if (empty($data) || !isset($data['_meta'])) {
            return false;
        }

        $meta = $data['_meta'];
        $now  = time();

        // Absolute lifetime
        if ($now - ($meta['created_at'] ?? 0) > $this->absoluteTtl) {
            return false;
        }

        // Idle timeout
        if ($now - ($meta['last_active'] ?? 0) > $this->ttl) {
            return false;
        }

        // IP binding
        if ($this->bindIp && isset($meta['ip']) && $meta['ip'] !== $this->currentIp) {
            return false;
        }

        // User-agent binding (hash comparison to avoid storing full UA)
        if ($this->bindUserAgent && isset($meta['ua_hash'])) {
            $expected = hash('sha256', $this->currentUa . $this->hmacSecret);
            if (!hash_equals($meta['ua_hash'], $expected)) {
                return false;
            }
        }

        return true;
    }

    private function buildMeta(): array
    {
        $meta = [
            'created_at'  => time(),
            'last_active' => time(),
        ];

        if ($this->bindIp && $this->currentIp !== '') {
            $meta['ip'] = $this->currentIp;
        }

        if ($this->bindUserAgent && $this->currentUa !== '') {
            $meta['ua_hash'] = hash('sha256', $this->currentUa . $this->hmacSecret);
        }

        return ['_meta' => $meta];
    }

    private function extractCookieValue(string $header, string $name): ?string
    {
        if ($header === '') {
            return null;
        }
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $k = trim(substr($part, 0, $eq));
            if ($k !== $name) {
                continue;
            }
            return urldecode(trim(substr($part, $eq + 1)));
        }
        return null;
    }
}
