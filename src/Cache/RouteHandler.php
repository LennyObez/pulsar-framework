<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use Pulsar\Api\Internal;

/**
 * Normalized route handler for cache serialization.
 *
 * All handler targets are container-resolvable strings.
 * At dispatch time, the router always resolves via the container.
 */
#[Internal]
final readonly class RouteHandler
{
    /**
     * @param RouteHandlerType $type Handler resolution strategy
     * @param string $resolvable Container-resolvable identifier (class-string, interface-string, or alias)
     * @param string|null $method Method name (null for Invokable, required for Method)
     */
    public function __construct(
        public RouteHandlerType $type,
        public string $resolvable,
        public ?string $method = null,
    ) {}
}
