<?php

declare(strict_types=1);

namespace Pulsar\Http\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a controller method or class as serving sensitive data that must
 * not be cached by browsers or intermediate proxies.
 *
 * When present, the NoCacheMiddleware adds:
 *   Cache-Control: no-store, no-cache, must-revalidate
 *   Pragma: no-cache
 *   Expires: 0
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class NoCacheResponse
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct() {}
}
