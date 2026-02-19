<?php

declare(strict_types=1);

namespace Pulsar\Live\Internal;

use Pulsar\Api\Internal;
use ReflectionProperty;

/**
 * Pre-computed metadata for one `#[LiveProp]` property on a `LiveComponent`.
 *
 * Built once per concrete component class by `ComponentHydrator` and
 * cached for the rest of the process lifetime — reflection lookup is
 * the dominant cost in the live render hot path, and class structure
 * cannot mutate at runtime, so the cache is sound (M-2 audit fix).
 */
#[Internal]
final readonly class LivePropertyDescriptor
{
    public function __construct(
        public string $name,
        public ReflectionProperty $property,
        public bool $writable,
        public string $typeName,
    ) {}
}
