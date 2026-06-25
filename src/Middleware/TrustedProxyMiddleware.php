<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Middleware;

use Nexphant\Server\ServerRequest;
use Nexphant\Server\ServerResponse;

/**
 * Trusted Proxy middleware.
 *
 * Rewrites remoteAddr, scheme, and host from forwarded headers
 * when the connection originates from a trusted proxy IP.
 */
class TrustedProxyMiddleware
{
    /** @param string[] $proxies CIDR or exact IPs trusted as proxies */
    public function __construct(
        private array  $proxies = [],
        private string $ipHeader    = 'x-forwarded-for',
        private string $protoHeader = 'x-forwarded-proto',
        private string $hostHeader  = 'x-forwarded-host',
    ) {}

    public function __invoke(ServerRequest $request, ServerResponse $response, callable $next): mixed
    {
        if ($this->isTrusted($request->remoteAddr)) {
            $forwarded = $request->header($this->ipHeader);
            if ($forwarded !== null) {
                // Take the first (leftmost) IP — the original client
                $ip = trim(explode(',', $forwarded)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $request->remoteAddr = $ip;
                }
            }
        }
        return $next($request, $response);
    }

    private function isTrusted(string $ip): bool
    {
        if (empty($this->proxies)) {
            return false;
        }
        foreach ($this->proxies as $proxy) {
            if ($proxy === $ip) {
                return true;
            }
            // Basic CIDR support
            if (str_contains($proxy, '/') && $this->cidrMatch($ip, $proxy)) {
                return true;
            }
        }
        return false;
    }

    private function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = $bits > 0 ? (~0 << (32 - $bits)) : 0;
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
