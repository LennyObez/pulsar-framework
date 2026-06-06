<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a property as an embedded value object.
 *
 * Value object properties are flattened into the owning entity's table
 * with an optional column prefix.
 *
 * @psalm-api PHP attribute consumed via reflection during ORM hydration.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class ValueObject
{
    /**
     * @param class-string $class The value object class
     * @param string $prefix Column prefix for embedded columns
     */
    public function __construct(
        public string $class,
        public string $prefix = '',
    ) {}
}
