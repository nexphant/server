<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Csrf;

use Nexphant\Server\Session\Session;

/**
 * CSRF token manager.
 *
 * Generates, rotates, and validates per-session CSRF tokens.
 */
class CsrfManager
{
    private const TOKEN_KEY = '_csrf_token';

    public function __construct(private Session $session) {}

    /** Return the current token, generating one if it doesn't exist. */
    public function token(): string
    {
        if (!$this->session->has(self::TOKEN_KEY)) {
            $this->session->set(self::TOKEN_KEY, $this->generate());
        }
        return (string) $this->session->get(self::TOKEN_KEY);
    }

    /** Issue a fresh token (invalidates the previous one). */
    public function refresh(): string
    {
        $token = $this->generate();
        $this->session->set(self::TOKEN_KEY, $token);
        return $token;
    }

    /** Validate a candidate token using constant-time comparison. */
    public function validate(string $candidate): bool
    {
        return hash_equals($this->token(), $candidate);
    }

    /** HTML hidden input field. */
    public function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($this->token(), ENT_QUOTES) . '">';
    }

    private function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
