<?php

declare(strict_types=1);

namespace Pulsar\Container\Lazy;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a service for lazy proxy wrapping.
 *
 * When resolved from the container, the service will be wrapped in a
 * native PHP lazy proxy (via ReflectionClass::newLazyProxy()). The actual
 * service is not instantiated until first method call.
 *
 * Cannot be applied to final classes: the AutoTagPass will throw a
 * ContainerException at compile time if this constraint is violated.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class Lazy {}
