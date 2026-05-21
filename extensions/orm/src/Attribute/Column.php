<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\ColumnType;

/**
 * Maps an entity property to a database column.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Column
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public ?string $name = null,
        public ColumnType $type = ColumnType::String,
        public bool $nullable = false,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $insertable = true,
        public bool $updatable = true,
    ) {}
}
