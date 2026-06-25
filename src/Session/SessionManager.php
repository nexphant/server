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

use Nexphant\Server\Session\Storage\ApcuSessionStorage;
use Nexphant\Server\Session\Storage\ArraySessionStorage;
use Nexphant\Server\Session\Storage\RedisSessionStorage;

/**
 * Factory / registry for session instances.
 * One Session object is created per request; this class manages the storage
 * driver and default options shared across the application.
 */
class SessionManager
{
    private SessionStorageInterface $storage;
    private array $options;

    public function __construct(SessionStorageInterface $storage, array $options = [])
    {
        $this->storage = $storage;
        $this->options = $options;
    }

    /**
     * Create a SessionManager from a configuration array.
     *
     * Config keys:
     *  driver        string  'apcu' | 'redis' | 'array'   (default: 'apcu')
     *  secret        string  HMAC secret — REQUIRED
     *  name          string  Cookie name                    (default: 'nxsess')
     *  ttl           int     Idle timeout in seconds        (default: 7200)
     *  absolute_ttl  int     Absolute lifetime in seconds   (default: 86400)
     *  bind_ip       bool    Bind session to client IP      (default: false)
     *  bind_ua       bool    Bind session to User-Agent     (default: true)
     *  cookie_path   string  Cookie Path attribute          (default: '/')
     *  cookie_domain string  Cookie Domain attribute        (default: '')
     *  cookie_secure bool    Cookie Secure flag             (default: true)
     *  cookie_samesite string Cookie SameSite value         (default: 'Lax')
     *  redis         mixed   Redis instance (driver=redis)
     *  redis_prefix  string  Key prefix for Redis/APCu      (default: 'sess:')
     */
    public static function fromConfig(array $config): self
    {
        if (empty($config['secret'])) {
            throw new \InvalidArgumentException('Session config requires a non-empty "secret"');
        }

        $driver = $config['driver'] ?? 'apcu';
        $prefix = $config['redis_prefix'] ?? 'sess:';

        $storage = match ($driver) {
            'redis' => new RedisSessionStorage(
                $config['redis'] ?? throw new \InvalidArgumentException('Session driver "redis" requires a "redis" instance'),
                $prefix
            ),
            'file'  => new \Nexphant\Server\Session\Storage\FileSessionStorage(
                $config['file_path'] ?? '',
                $config['file_prefix'] ?? 'sess_'
            ),
            'array' => new ArraySessionStorage(),
            default => new ApcuSessionStorage($prefix),
        };

        return new self($storage, $config);
    }

    /**
     * Make a new Session for a single request.
     * Caller should pass current IP and UA for binding checks.
     */
    public function make(string $ip = '', string $ua = ''): Session
    {
        return new Session($this->storage, array_merge($this->options, [
            'ip' => $ip,
            'ua' => $ua,
        ]));
    }

    public function storage(): SessionStorageInterface
    {
        return $this->storage;
    }
}
