<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Maps an entity class to a database table.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class Table
{
    public function __construct(
        public string $name,
        public ?string $schema = null,
    ) {}
}
