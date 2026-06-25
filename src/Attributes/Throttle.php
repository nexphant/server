<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Attributes;

use Attribute;

/**
 * Throttle attribute for route-level rate limiting.
 *
 * Usage:
 *   #[Throttle(60, 1)]  // 60 requests per minute
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
class Throttle
{
    public function __construct(
        public readonly int    $maxRequests = 60,
        public readonly int    $windowMinutes = 1,
        public readonly string $by = 'ip', // 'ip' | 'user' | 'global'
    ) {}
}
