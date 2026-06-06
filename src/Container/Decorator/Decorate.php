<?php

declare(strict_types=1);

namespace Pulsar\Container\Decorator;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a class as a decorator for a specific service.
 *
 * The AutoTagPass scans for this attribute and populates the target
 * service's decorator chain.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class Decorate
{
    /**
     * @param string $decorates Service ID to wrap (typically an interface FQCN)
     * @param int $priority Application order (higher = outermost wrapper)
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $decorates,
        public int $priority = 0,
    ) {}
}
