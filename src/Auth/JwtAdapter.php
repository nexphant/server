<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Auth;

/**
 * JwtAdapter — lightweight JWT encode/decode for API authentication.
 *
 * Supports HS256 signing only. Does NOT require an external library.
 */
class JwtAdapter
{
    public function __construct(private readonly string $secret) {}

    /**
     * Encode a payload as a signed JWT.
     */
    public function encode(array $payload, int $ttl = 3600): string
    {
        $payload['iat'] ??= time();
        $payload['exp'] ??= time() + $ttl;

        $header    = $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body      = $this->base64url(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->sign("{$header}.{$body}");

        return "{$header}.{$body}.{$signature}";
    }

    /**
     * Decode and verify a JWT. Returns payload or throws on failure.
     *
     * @throws \RuntimeException on invalid/expired token
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWT format.');
        }

        [$header, $body, $sig] = $parts;

        $expected = $this->sign("{$header}.{$body}");
        if (!hash_equals($expected, $sig)) {
            throw new \RuntimeException('JWT signature mismatch.');
        }

        $payload = json_decode($this->base64urlDecode($body), true, 512, JSON_THROW_ON_ERROR);

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new \RuntimeException('JWT token has expired.');
        }

        return $payload;
    }

    // -------------------------------------------------------------------------

    private function sign(string $data): string
    {
        return $this->base64url(hash_hmac('sha256', $data, $this->secret, true));
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
