<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a property as the entity's primary key.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Id
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public bool $autoIncrement = true,
    ) {}
}
