<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Internal;

/**
 * Diagnostic record of two routes registered under the same (method, path, host)
 * key.
 *
 * Registration is first-registered-wins: framework wirings register before
 * project routes, which register before extensions, so the {@see $winner} is the
 * more specific/authoritative route and the later {@see $shadowed} route is
 * excluded from the match tables (it would otherwise silently override the
 * winner). The boot-time {@see \Pulsar\Core\Boot\RouteCollisionReporter} turns
 * these records into a warning (or a hard failure in debug mode).
 *
 * @internal Diagnostic value object read by the collision reporter; not a
 *           stability promise and not intended for application use.
 */
#[Internal]
final readonly class RouteCollision
{
    public function __construct(
        public string $method,
        public string $path,
        public ?string $host,
        public Route $winner,
        public Route $shadowed,
    ) {}
}
